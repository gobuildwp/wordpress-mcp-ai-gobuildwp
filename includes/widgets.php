<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Classic widgets: reading and writing sidebar contents, and the ledger undo for those writes.
 *
 * WordPress keeps widgets in two kinds of option: sidebars_widgets maps each sidebar to the
 * widget ids in it ("text-3", "block-7"), and widget_{id_base} holds every instance of one widget
 * type, keyed by number. A write here touches one or two sidebars and one instance, so the undo
 * snapshot holds exactly those parts, not the whole options: undoing one widget change leaves
 * every other sidebar and widget as it is now.
 *
 * The undo lives here rather than beside the abilities because a rollback can run on a request
 * that never initialises the abilities registry (the Changes screen).
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_WIDGETS_TYPE = 'widgets/parts';

/** The sidebar WordPress keeps unused widgets in; not registered, but always valid. */
const WPPILOT_WIDGETS_INACTIVE = 'wp_inactive_widgets';

/** Largest block markup one widget accepts. */
const WPPILOT_WIDGETS_MAX_CONTENT = 100_000;

/**
 * Split a widget id ("text-3") into its type and number.
 *
 * @return array{id_base: string, number: int}|null
 */
function wppilot_widget_parse_id(string $widget_id): ?array
{
    if (preg_match('/^([a-z0-9_-]+)-(\d+)$/i', $widget_id, $m) !== 1 || (int) $m[2] <= 0) {
        return null;
    }
    return ['id_base' => $m[1], 'number' => (int) $m[2]];
}

/**
 * Registered multi-instance widget types, by id_base.
 *
 * @return array<string, object>
 */
function wppilot_widget_types(): array
{
    $factory = $GLOBALS['wp_widget_factory'] ?? null;
    $types = [];
    if (!is_object($factory) || !isset($factory->widgets) || !is_array($factory->widgets)) {
        return $types;
    }
    foreach ($factory->widgets as $widget) {
        if (is_object($widget) && isset($widget->id_base) && is_string($widget->id_base) && method_exists($widget, 'update')) {
            $types[$widget->id_base] = $widget;
        }
    }
    return $types;
}

/**
 * The theme's registered sidebars, by id.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_widget_sidebars(): array
{
    $registered = $GLOBALS['wp_registered_sidebars'] ?? [];
    $sidebars = [];
    foreach (is_array($registered) ? $registered : [] as $id => $sidebar) {
        if (is_array($sidebar)) {
            $sidebars[(string) $id] = $sidebar;
        }
    }
    return $sidebars;
}

/**
 * sidebars_widgets as stored, without array_version.
 *
 * @return array<string, list<string>>
 */
function wppilot_widget_sidebar_map(): array
{
    $stored = get_option('sidebars_widgets', []);
    $map = [];
    foreach (is_array($stored) ? $stored : [] as $id => $ids) {
        if ($id === 'array_version' || !is_array($ids)) {
            continue;
        }
        $map[(string) $id] = array_values(array_map('strval', $ids));
    }
    return $map;
}

/**
 * The stored instances of one widget type, by number.
 *
 * @return array<int, array<string, mixed>>
 */
function wppilot_widget_instances(string $id_base): array
{
    $stored = get_option('widget_' . $id_base, []);
    $instances = [];
    foreach (is_array($stored) ? $stored : [] as $number => $instance) {
        if (is_int($number) && is_array($instance)) {
            $instances[$number] = $instance;
        }
    }
    return $instances;
}

/** The sidebar a widget id sits in, or null when it is in none. */
function wppilot_widget_find_sidebar(string $widget_id): ?string
{
    foreach (wppilot_widget_sidebar_map() as $sidebar_id => $ids) {
        if (in_array($widget_id, $ids, true)) {
            return $sidebar_id;
        }
    }
    return null;
}

/**
 * The parts a write touches: these sidebars' widget lists and these widget instances.
 *
 * A null value means "absent": the sidebar has no entry, or the instance does not exist.
 *
 * @param list<string> $sidebar_ids
 * @param list<string> $widget_ids
 * @return array{sidebars: array<string, list<string>|null>, widgets: array<string, array<string, mixed>|null>}
 */
function wppilot_widget_read_parts(array $sidebar_ids, array $widget_ids): array
{
    $map = wppilot_widget_sidebar_map();
    $parts = ['sidebars' => [], 'widgets' => []];
    foreach (array_unique($sidebar_ids) as $sidebar_id) {
        $parts['sidebars'][$sidebar_id] = $map[$sidebar_id] ?? null;
    }
    foreach (array_unique($widget_ids) as $widget_id) {
        $parsed = wppilot_widget_parse_id($widget_id);
        $parts['widgets'][$widget_id] = $parsed === null ? null : (wppilot_widget_instances($parsed['id_base'])[$parsed['number']] ?? null);
    }
    return $parts;
}

/**
 * Write sidebar lists and widget instances back, leaving everything else in the options alone.
 *
 * @param array{sidebars?: array<string, list<string>|null>, widgets?: array<string, array<string, mixed>|null>} $parts
 */
function wppilot_widget_write_parts(array $parts): void
{
    $by_base = [];
    foreach ($parts['widgets'] ?? [] as $widget_id => $instance) {
        $parsed = wppilot_widget_parse_id((string) $widget_id);
        if ($parsed !== null) {
            $by_base[$parsed['id_base']][$parsed['number']] = $instance;
        }
    }
    foreach ($by_base as $id_base => $numbers) {
        $stored = get_option('widget_' . $id_base, []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($numbers as $number => $instance) {
            if ($instance === null) {
                unset($stored[$number]);
            } else {
                $stored[$number] = $instance;
            }
        }
        $stored['_multiwidget'] = 1;
        update_option('widget_' . $id_base, $stored);
    }

    if (($parts['sidebars'] ?? []) !== []) {
        $stored = get_option('sidebars_widgets', []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($parts['sidebars'] as $sidebar_id => $ids) {
            if ($ids === null) {
                unset($stored[$sidebar_id]);
            } else {
                $stored[$sidebar_id] = array_values($ids);
            }
        }
        $stored['array_version'] = 3;
        update_option('sidebars_widgets', $stored);
    }
}

/** Next free instance number for a widget type. WordPress starts at 2. */
function wppilot_widget_next_number(string $id_base): int
{
    $numbers = array_keys(wppilot_widget_instances($id_base));
    return $numbers === [] ? 2 : max(1, max($numbers)) + 1;
}

/**
 * Run a widget type's own update() over new settings, the way the widgets screen saves them.
 *
 * update() is what sanitises: the block and text widgets strip unfiltered HTML for users
 * without unfiltered_html there. A widget that returns false refuses the save.
 *
 * @param array<string, mixed> $new
 * @param array<string, mixed> $old
 * @return array<string, mixed>|WP_Error
 */
function wppilot_widget_sanitize(object $widget, array $new, array $old): array|WP_Error
{
    // @mago-expect analysis:mixed-assignment -- Third-party widget output is checked below.
    $instance = $widget->update($new, $old);
    if (!is_array($instance)) {
        return new WP_Error('wppilot_widget_rejected', sprintf('The "%s" widget refused these settings. Nothing was changed.', (string) $widget->id_base), ['status' => 422]);
    }
    return $instance;
}

/**
 * One widget as an agent sees it.
 *
 * @return array<string, mixed>
 */
function wppilot_widget_summary(string $widget_id, bool $full = false): array
{
    $parsed = wppilot_widget_parse_id($widget_id);
    $types = wppilot_widget_types();
    $out = ['widget_id' => $widget_id];
    if ($parsed === null || !isset($types[$parsed['id_base']])) {
        $out['kind'] = 'unsupported';
        $out['note'] = $parsed === null
            ? 'A single-instance legacy widget. It cannot be changed here.'
            : 'A widget type this site no longer registers. It can only be removed.';
        return $out;
    }
    $widget = $types[$parsed['id_base']];
    $instance = wppilot_widget_instances($parsed['id_base'])[$parsed['number']] ?? [];
    $out['id_base'] = $parsed['id_base'];
    $out['name'] = isset($widget->name) ? (string) $widget->name : $parsed['id_base'];
    $out['kind'] = $parsed['id_base'] === 'block' ? 'block' : 'legacy';
    if ($out['kind'] === 'block') {
        $content = (string) ($instance['content'] ?? '');
        $out['content'] = $full || strlen($content) <= 2000 ? $content : substr($content, 0, 2000);
        $out['truncated'] = !$full && strlen($content) > 2000;
        return $out;
    }
    $out['title'] = (string) ($instance['title'] ?? '');
    $encoded = (string) wp_json_encode($instance);
    $out['settings'] = $full || strlen($encoded) <= 4000 ? $instance : ['_note' => 'Large settings; read this widget with widget_id for all of them.'];
    return $out;
}

/**
 * Ledger row for one widget write: the parts before, and a fingerprint of the parts after so an
 * undo can tell whether someone changed them since.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $input
 * @param array<string, mixed> $result
 * @return array<string, mixed> $result with change_id added when the ledger is loaded.
 */
function wppilot_widget_record(string $ability, array $before, array $input, array $result): array
{
    if (!function_exists('wppilot_ledger_record_items')) {
        return $result;
    }
    $after = wppilot_widget_read_parts(array_keys($before['sidebars']), array_keys($before['widgets']));
    $ledger = wppilot_ledger_record_items($ability, [[
        'input' => $input,
        'before' => [
            'type' => WPPILOT_WIDGETS_TYPE,
            'parts' => $before,
            'after_fingerprint' => wppilot_snapshot_fingerprint($after),
        ],
        'result' => $result,
        'item' => ['widget_id' => (string) ($result['widget_id'] ?? '')],
    ]]);
    $result['change_id'] = $ledger['change_ids'][0] ?? '';
    return $result;
}

/**
 * Undo a widget change: put the recorded sidebars and instance back.
 *
 * Refused, unless forced, when those sidebars or that instance changed after the write.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function wppilot_widget_rollback(array $payload, array $entry): array|WP_Error
{
    $snapshot = wppilot_string_keyed_array($payload['snapshot'] ?? null);
    $parts = wppilot_string_keyed_array($snapshot['parts'] ?? null);
    $sidebars = wppilot_string_keyed_array($parts['sidebars'] ?? null);
    $widgets = wppilot_string_keyed_array($parts['widgets'] ?? null);
    if ($sidebars === [] && $widgets === []) {
        return new WP_Error('wppilot_rollback_invalid_widgets', 'The change record holds no widget state to restore.');
    }
    $restore = ['sidebars' => [], 'widgets' => []];
    foreach ($sidebars as $id => $ids) {
        $restore['sidebars'][$id] = is_array($ids) ? array_values(array_map('strval', $ids)) : null;
    }
    foreach ($widgets as $id => $instance) {
        if (wppilot_widget_parse_id($id) === null) {
            return new WP_Error('wppilot_rollback_invalid_widgets', 'The change record names an invalid widget id.');
        }
        $restore['widgets'][$id] = is_array($instance) ? $instance : null;
    }

    $now = wppilot_widget_read_parts(array_keys($restore['sidebars']), array_keys($restore['widgets']));
    $force = ($entry['force'] ?? false) === true;
    if (!$force && wppilot_snapshot_fingerprint($now) !== (string) ($snapshot['after_fingerprint'] ?? '')) {
        return new WP_Error('wppilot_change_changed_since', sprintf(
            'The widgets changed after this change (%s), so undoing it would overwrite that. Nothing was changed. Undo with force=true to put the recorded state back anyway.',
            implode(', ', array_merge(array_keys($restore['sidebars']), array_keys($restore['widgets']))),
        ), ['status' => 409]);
    }

    wppilot_widget_write_parts($restore);
    $read = wppilot_widget_read_parts(array_keys($restore['sidebars']), array_keys($restore['widgets']));

    return [
        'sidebars' => array_keys($restore['sidebars']),
        'widgets' => array_keys($restore['widgets']),
        'verified' => wppilot_snapshot_fingerprint($read) === wppilot_snapshot_fingerprint($restore),
    ];
}

if (function_exists('wppilot_register_rollback_strategy')) {
    wppilot_register_rollback_strategy(WPPILOT_WIDGETS_TYPE, 'wppilot_widget_rollback');
}
