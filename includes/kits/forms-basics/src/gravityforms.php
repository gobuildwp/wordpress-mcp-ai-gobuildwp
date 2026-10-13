<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Gravity Forms (2.7 and later): the form list and entries, through GFAPI.
 *
 * GFAPI::get_forms() does not page, so the list is filtered, sorted and cut here, and the entry
 * counts come from one GROUP BY over GF's entry table. An entry keeps each answer under the
 * field's id, or under "<field id>.<input id>" for a field of several inputs (name, address,
 * checkboxes). The form list is the same answer GoBuildWP Pro 1.10.0's gravityforms-list-forms gives.
 */

const GF_MIN_VERSION = '2.7';

const GF_LIST_LIMIT_MAX = 500;

const GF_LIST_LIMIT_DEFAULT = 50;

/** Field types that hold no answer. */
const GF_LAYOUT_TYPES = ['section', 'page', 'html', 'captcha'];

function gf_available(): bool
{
    if (!class_exists('GFForms') || !class_exists('GFAPI')) {
        return false;
    }
    $installed = (string) (\GFForms::$version ?? '');
    return $installed !== '' && version_compare($installed, GF_MIN_VERSION, '>=');
}

function gf_not_active(): WP_Error
{
    return new WP_Error('gravityforms_not_active', 'Gravity Forms is not active or does not meet the minimum supported version.', ['status' => 400]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gf_list_forms(array $input): array|WP_Error
{
    if (!gf_available()) {
        return gf_not_active();
    }
    $limit = isset($input['limit']) ? max(1, min(GF_LIST_LIMIT_MAX, (int) $input['limit'])) : GF_LIST_LIMIT_DEFAULT;
    $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
    $status = (string) ($input['status'] ?? 'any');
    $orderby = (string) ($input['orderby'] ?? 'date_updated');
    $order = strtoupper((string) ($input['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
    $search = isset($input['search']) ? trim((string) $input['search']) : '';

    $forms = gf_forms_for_status($status);
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $forms = array_values(array_filter($forms, static fn(array $f): bool => str_contains(mb_strtolower((string) ($f['title'] ?? '')), $needle)));
    }
    $direction = $order === 'ASC' ? 1 : -1;
    usort($forms, static function (array $a, array $b) use ($orderby, $direction): int {
        $va = (string) ($a[$orderby] ?? '');
        $vb = (string) ($b[$orderby] ?? '');
        return ($orderby === 'id' ? ((int) $va <=> (int) $vb) : strcmp($va, $vb)) * $direction;
    });

    $total = count($forms);
    $slice = array_slice($forms, $offset, $limit);
    $counts = gf_entry_counts(array_map(static fn(array $f): int => (int) $f['id'], $slice));
    $rows = [];
    foreach ($slice as $form) {
        $is_trash = (int) ($form['is_trash'] ?? 0) === 1;
        $is_active = (int) ($form['is_active'] ?? 1) === 1;
        $rows[] = [
            'id' => (int) ($form['id'] ?? 0),
            'title' => (string) ($form['title'] ?? ''),
            'status' => $is_trash ? 'trash' : ($is_active ? 'active' : 'inactive'),
            'date_created' => (string) ($form['date_created'] ?? ''),
            'date_updated' => (string) ($form['date_updated'] ?? $form['date_created'] ?? ''),
            'entry_count' => $counts[(int) ($form['id'] ?? 0)] ?? 0,
        ];
    }

    return ['total' => $total, 'limit' => $limit, 'offset' => $offset, 'forms' => $rows];
}

/**
 * The forms matching a status. get_forms($active, $trash) means "is_active = $active AND is_trash
 * = $trash", so "any" (active and inactive, not trashed) and "trash" (either) take two calls.
 *
 * @return list<array<string, mixed>>
 */
function gf_forms_for_status(string $status): array
{
    $get = static fn(bool $active, bool $trash): array => array_values(array_filter((array) \GFAPI::get_forms($active, $trash), 'is_array'));
    return match ($status) {
        'active' => $get(true, false),
        'inactive' => $get(false, false),
        'trash' => array_merge($get(true, true), $get(false, true)),
        default => array_merge($get(true, false), $get(false, false)),
    };
}

/**
 * Active entries per form id, in one query.
 *
 * @param list<int> $form_ids
 * @return array<int, int>
 */
function gf_entry_counts(array $form_ids): array
{
    $ids = array_values(array_filter(array_map('intval', $form_ids), static fn(int $id): bool => $id > 0));
    if ($ids === [] || !class_exists('GFFormsModel') || !method_exists('GFFormsModel', 'get_entry_table_name')) {
        return [];
    }
    $table = (string) \GFFormsModel::get_entry_table_name();
    if ($table === '') {
        return [];
    }
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    /** @var mixed $rows */
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT form_id, COUNT(*) AS total FROM {$table} WHERE form_id IN ({$placeholders}) AND status = 'active' GROUP BY form_id",
        $ids,
    ));
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_object($row)) {
            $out[(int) $row->form_id] = (int) $row->total;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function gf_list_entries(array $input): array|WP_Error
{
    if (!gf_available()) {
        return gf_not_active();
    }
    $form_id = is_numeric($input['form_id'] ?? null) ? (int) $input['form_id'] : 0;
    if ($form_id <= 0) {
        return new WP_Error('gravityforms_invalid_input', 'form_id must be a positive integer.', ['status' => 400]);
    }
    /** @var mixed $form */
    $form = \GFAPI::get_form($form_id);
    if (!is_array($form)) {
        return new WP_Error('gravityforms_form_not_found', "Gravity Forms form not found: {$form_id}", ['status' => 404]);
    }
    if ((int) ($form['is_trash'] ?? 0) === 1) {
        return new WP_Error('gravityforms_form_trashed', "Form {$form_id} is in trash.", ['status' => 410]);
    }
    // Through GF's own check, so the gform_user_can_any filter (per-form access) applies.
    if (!\GFAPI::current_user_can_any(['gravityforms_view_entries'])) {
        return new WP_Error('gravityforms_capability_missing', "Current user lacks the required 'gravityforms_view_entries' capability.", ['status' => 403]);
    }
    $from = date_bound($input['date_from'] ?? '', false);
    $to = date_bound($input['date_to'] ?? '', true);
    if ($from instanceof WP_Error) {
        return $from;
    }
    if ($to instanceof WP_Error) {
        return $to;
    }

    $limit = entries_limit($input['limit'] ?? null);
    $offset = entries_offset($input['offset'] ?? null);
    $status = is_string($input['status'] ?? null) ? $input['status'] : 'active';
    $criteria = ['status' => $status === 'any' ? '' : $status];
    if ($from !== '') {
        $criteria['start_date'] = $from;
    }
    if ($to !== '') {
        $criteria['end_date'] = $to;
    }
    $filters = [];
    if (isset($input['starred']) && is_bool($input['starred'])) {
        $filters[] = ['key' => 'is_starred', 'value' => $input['starred'] ? 1 : 0];
    }
    if (isset($input['read']) && is_bool($input['read'])) {
        $filters[] = ['key' => 'is_read', 'value' => $input['read'] ? 1 : 0];
    }
    if ($filters !== []) {
        $filters['mode'] = 'all';
        $criteria['field_filters'] = $filters;
    }
    $orderby = in_array($input['orderby'] ?? null, ['id', 'date_created', 'date_updated'], true) ? (string) $input['orderby'] : 'date_created';
    $sorting = ['key' => $orderby, 'direction' => strtoupper((string) ($input['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC'];

    $total = 0;
    /** @var mixed $rows */
    $rows = \GFAPI::get_entries($form_id, $criteria, $sorting, ['offset' => $offset, 'page_size' => $limit], $total);
    if ($rows instanceof WP_Error) {
        return $rows;
    }
    $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    $entries = [];
    foreach (is_array($rows) ? $rows : [] as $entry) {
        if (is_array($entry)) {
            $entries[] = gf_entry($entry, $fields);
        }
    }
    return [
        'form_id' => $form_id,
        'total' => (int) $total,
        'limit' => $limit,
        'offset' => $offset,
        'redaction' => REDACTION_MODE,
        'entries' => $entries,
    ];
}

/**
 * A property of a GF_Field object or of the plain array a form export holds.
 */
function gf_prop(mixed $field, string $prop): mixed
{
    if (is_array($field)) {
        return $field[$prop] ?? null;
    }
    return is_object($field) ? ($field->{$prop} ?? null) : null;
}

/**
 * One entry: GoBuildWP Pro's compact row with the IP withheld, and each answer, redacted.
 *
 * @param array<array-key, mixed> $entry
 * @param array<array-key, mixed> $fields
 * @return array<string, mixed>
 */
function gf_entry(array $entry, array $fields): array
{
    $answers = [];
    foreach ($fields as $field) {
        $id = gf_prop($field, 'id');
        $type = gf_prop($field, 'type');
        $type = is_string($type) ? $type : '';
        if ((!is_int($id) && !is_string($id)) || in_array($type, GF_LAYOUT_TYPES, strict: true)) {
            continue;
        }
        $label = gf_prop($field, 'label');
        $label = is_string($label) ? $label : '';
        $inputs = gf_prop($field, 'inputs');
        if (is_array($inputs) && $inputs !== []) {
            $value = [];
            foreach ($inputs as $input) {
                $input_id = gf_prop($input, 'id');
                if (!is_scalar($input_id)) {
                    continue;
                }
                $part = $entry[(string) $input_id] ?? '';
                if ($part !== '' && $part !== null) {
                    $part_label = gf_prop($input, 'label');
                    $value[is_string($part_label) && $part_label !== '' ? $part_label : (string) $input_id] = $part;
                }
            }
        } else {
            $value = $entry[(string) $id] ?? '';
        }
        if (is_empty($value)) {
            continue;
        }
        $answers[] = answer((string) $id, $label, $type, $type, $value);
    }
    return [
        'id' => (int) ($entry['id'] ?? 0),
        'form_id' => (int) ($entry['form_id'] ?? 0),
        'status' => (string) ($entry['status'] ?? 'active'),
        'date_created' => (string) ($entry['date_created'] ?? ''),
        'date_updated' => (string) ($entry['date_updated'] ?? $entry['date_created'] ?? ''),
        'is_read' => (int) ($entry['is_read'] ?? 0) === 1,
        'is_starred' => (int) ($entry['is_starred'] ?? 0) === 1,
        'ip' => is_empty($entry['ip'] ?? '') ? '' : REDACTED,
        'answers' => $answers,
    ];
}
