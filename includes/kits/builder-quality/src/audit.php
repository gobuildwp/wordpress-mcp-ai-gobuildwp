<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BuilderQuality;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * How editable an Elementor page is, judged from its saved element tree.
 *
 * An agent can build a page that looks right and is miserable to edit: the layout pasted into
 * one HTML widget, shortcodes standing in for widgets, inline styles and hard-coded colours that
 * ignore the site's global colours, containers nested ten deep. Each finding below costs points
 * from 100 and comes with the fix; the score is a guide for "is this page done", not a law.
 */

const MAX_ITEMS = 200;

const DEEP_NESTING = 6;

/** What each finding costs, and the most one kind of finding may cost in total. */
const PENALTIES = [
    'html_widget' => [20, 40],
    'script' => [15, 30],
    'shortcode_widget' => [5, 20],
    'unknown_widget' => [5, 20],
    'inline_style' => [3, 15],
    'custom_css' => [3, 12],
    'hardcoded_color' => [1, 15],
    'deep_nesting' => [2, 10],
    'empty_container' => [1, 5],
];

const FIXES = [
    'html_widget' => 'Rebuild the markup with native widgets (heading, text editor, button, image), so it can be edited in the panel.',
    'script' => 'Move the script out of the page: a plugin, the theme, or Elementor\'s Custom Code. Scripts inside content break on caching, and editors cannot see them.',
    'shortcode_widget' => 'Use the plugin\'s Elementor widget if it has one, so the content shows in the editor.',
    'unknown_widget' => 'This widget\'s plugin is not active, so it renders nothing. Activate it or replace the widget.',
    'inline_style' => 'Drop the style attribute and set the look with the widget\'s style controls or a global class.',
    'custom_css' => 'Move custom CSS to a global class or the site kit, where it can be reused and found.',
    'hardcoded_color' => 'Pick a global colour instead of a fixed value, so a palette change reaches this element.',
    'deep_nesting' => 'Flatten the layout: each extra container is another box to click through and more markup to load.',
    'empty_container' => 'Remove the empty container or put content in it.',
];

/** Classic containers and the atomic (v4) ones. */
function is_container(array $element): bool
{
    return in_array($element['elType'] ?? '', ['section', 'column', 'container', 'e-flexbox', 'e-div-block', 'e-grid'], strict: true);
}

/**
 * Text a widget holds, for the content checks: the classic HTML and text-editor fields, and an
 * atomic prop's string value.
 */
function content_of(array $settings): string
{
    $parts = [];
    foreach (['html', 'editor', 'shortcode', 'description', 'text'] as $key) {
        $value = $settings[$key] ?? null;
        if (is_string($value)) {
            $parts[] = $value;
        } elseif (is_array($value) && is_string($value['value'] ?? null)) {
            $parts[] = $value['value'];
        }
    }

    return implode("\n", $parts);
}

/**
 * Colour settings set to a fixed value rather than a global. Classic widgets keep globals under
 * `__globals__`; a key set there wins over the fixed value.
 *
 * @return list<string>
 */
function hardcoded_colors(array $settings): array
{
    $globals = is_array($settings['__globals__'] ?? null) ? $settings['__globals__'] : [];
    $found = [];
    foreach ($settings as $key => $value) {
        if (!is_string($key) || !str_ends_with($key, 'color') || !is_string($value)) {
            continue;
        }
        if (preg_match('/^(#[0-9a-f]{3,8}|rgba?\()/i', trim($value)) === 1 && empty($globals[$key])) {
            $found[] = $key;
        }
    }

    return $found;
}

/**
 * @param list<array<string, mixed>> $elements
 * @param callable(string): bool      $widget_known
 * @param list<array<string, mixed>> $items
 */
function walk(array $elements, int $depth, callable $widget_known, array &$items): void
{
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        $id = (string) ($element['id'] ?? '');
        $type = (string) ($element['widgetType'] ?? $element['elType'] ?? '');
        $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
        $children = is_array($element['elements'] ?? null) ? $element['elements'] : [];
        $add = static function (string $issue, string $detail = '') use (&$items, $id, $type): void {
            $items[] = ['element_id' => $id, 'element' => $type, 'issue' => $issue, 'detail' => $detail];
        };

        if (is_container($element)) {
            if ($depth > DEEP_NESTING) {
                $add('deep_nesting', sprintf('%d containers deep', $depth));
            }
            if ($children === []) {
                $add('empty_container');
            }
        } elseif (($element['elType'] ?? '') === 'widget') {
            if ($type === 'html') {
                $add('html_widget');
            } elseif ($type === 'shortcode') {
                $add('shortcode_widget', substr((string) ($settings['shortcode'] ?? ''), 0, 80));
            } elseif ($type !== '' && !$widget_known($type)) {
                $add('unknown_widget', $type);
            }
            $content = content_of($settings);
            if (stripos($content, '<script') !== false) {
                $add('script');
            }
            if (preg_match('/\sstyle\s*=\s*["\']/i', $content) === 1) {
                $add('inline_style');
            }
        }
        if (is_string($settings['custom_css'] ?? null) && trim($settings['custom_css']) !== '') {
            $add('custom_css');
        }
        foreach (hardcoded_colors($settings) as $key) {
            $add('hardcoded_color', $key);
        }

        walk($children, is_container($element) ? $depth + 1 : $depth, $widget_known, $items);
    }
}

/**
 * The audit of one element tree. Pure: the caller supplies the tree and how to tell a widget
 * the site knows from one it does not.
 *
 * @param list<array<string, mixed>> $tree
 * @param callable(string): bool      $widget_known
 * @return array<string, mixed>
 */
function audit_tree(array $tree, callable $widget_known): array
{
    $items = [];
    walk($tree, 1, $widget_known, $items);

    $counts = [];
    foreach ($items as $item) {
        $counts[$item['issue']] = ($counts[$item['issue']] ?? 0) + 1;
    }
    $lost = 0;
    foreach ($counts as $issue => $n) {
        [$each, $cap] = PENALTIES[$issue];
        $lost += min($cap, $each * $n);
    }
    $score = max(0, 100 - $lost);
    arsort($counts);

    return [
        'score' => $score,
        'grade' => $score >= 85 ? 'good' : ($score >= 60 ? 'fair' : 'poor'),
        'counts' => $counts,
        'fixes' => array_intersect_key(FIXES, $counts),
        'items' => array_slice($items, 0, MAX_ITEMS),
        'items_total' => count($items),
    ];
}

/**
 * wppilot/elementor-audit-output
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function audit(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post) {
        return new WP_Error('kit_builder_quality_post', 'No such post.', ['status' => 404]);
    }
    if (get_post_meta($post_id, '_elementor_edit_mode', true) !== 'builder') {
        return new WP_Error('kit_builder_quality_not_elementor', 'This post is not built with Elementor.', ['status' => 400]);
    }
    $raw = get_post_meta($post_id, '_elementor_data', true);
    $tree = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    if (!is_array($tree)) {
        return new WP_Error('kit_builder_quality_data', 'The page\'s Elementor data could not be read.', ['status' => 500]);
    }

    $manager = class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->widgets_manager) ? \Elementor\Plugin::$instance->widgets_manager : null;
    $known = static fn(string $type): bool => $manager === null || $manager->get_widget_types($type) !== null;

    return ['post_id' => $post_id, 'title' => get_the_title($post_id)] + audit_tree($tree, $known);
}
