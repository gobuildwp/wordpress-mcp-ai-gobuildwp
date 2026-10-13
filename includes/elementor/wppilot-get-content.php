<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use WP_Post;

/**
 * Ability: Read the Elementor document tree for a post.
 *
 * Output is COMPACT by default — a skeleton tree with only element ids,
 * types, and children — so a caller can understand a page's structure
 * without paying for the full settings blob. Progressive disclosure is
 * layered on top:
 *
 *     element_id: "abc1234"   return only that element (and its children)
 *                             with full settings — the RIGHT way to drill
 *                             after seeing the skeleton
 *     full_dump: true         attach every element's settings — use ONLY
 *                             for full page clones / audits, NEVER as a
 *                             "give me everything" first call
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-get-content', [
    'label' => __('Get Elementor Content', domain: 'wppilot'),
    'description' => __(
        'Reads the Elementor document tree for a post. Output is COMPACT by default — a structural skeleton with only element ids, types, widget types, and children (no settings). The skeleton is small (typically a few hundred tokens) and is the right first call for tasks like "remake this page", "edit a widget", "understand the layout". After the skeleton, drill into the widget you care about with element_id:"<id>" — you get back that subtree (plus children) with full settings, cheaply. Do NOT pass full_dump:true unless you genuinely need every widget\'s settings at once (e.g. cloning the entire page verbatim or running a full audit); for every other use case it wastes thousands of tokens on data you will not use.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The WordPress post ID.',
            ],
            'element_id' => [
                'type' => 'string',
                'description' => 'Return only the subtree rooted at this element id, with full settings. This is the cheap, targeted way to zoom into one widget after reading the skeleton. Prefer this over full_dump.',
            ],
            'full_dump' => [
                'type' => 'boolean',
                'description' => 'HEAVY. When true, every element in the tree includes its full settings — expect thousands of tokens for a real page. Use ONLY for whole-page clones or audits where you really need every widget at once. For remakes, edits, or structure understanding prefer the default skeleton + element_id drill. Defaults to false.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'post_id' => ['type' => 'integer'],
            'post_title' => ['type' => 'string'],
            'template_type' => ['type' => 'string'],
            'content' => [
                'type' => 'array',
                'description' => 'Parsed Elementor element tree. Compact skeleton by default, single subtree when element_id is used, full dump when full_dump=true.',
                'items' => ['type' => 'object'],
            ],
            'element_count' => ['type' => 'integer'],
            'has_element_cache' => [
                'type' => 'boolean',
                'description' => 'True when Elementor Pro would serve a cached render for this document, so a programmatic edit will not show on the front end until the cache is cleared with wppilot/elementor-clear-document-cache.',
            ],
            'cache_expires_at' => [
                'type' => 'integer',
                'description' => 'When has_element_cache is true: the cached render\'s expiry as Elementor stores it (a current_time()-based timestamp).',
            ],
            'cache_expires_in_seconds' => [
                'type' => 'integer',
                'description' => 'When has_element_cache is true: seconds until the cached render expires.',
            ],
            'hint' => [
                'type' => 'string',
                'description' => 'Progressive-disclosure tip describing how to drill deeper when the default compact view is not enough.',
            ],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_get_content',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function elementor_get_content(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    /** @var WP_Post|null $post */
    $post = $post_id > 0 ? get_post($post_id) : null;
    if (!$post) {
        return ['success' => false, 'error' => "Post {$post_id} not found."];
    }

    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    /** @var mixed $template_type */
    $template_type = get_post_meta($post_id, key: '_elementor_template_type', single: true);

    $focus_id = (string) ($input['element_id'] ?? '');
    if ($focus_id !== '') {
        $found = el_find($elements, $focus_id);
        if ($found === null) {
            return ['success' => false, 'error' => "Element '{$focus_id}' not found on post {$post_id}."];
        }
        $elements = [$found];
    }

    $compact = $focus_id === '' && ($input['full_dump'] ?? false) !== true;

    $payload = [
        'success' => true,
        'post_id' => $post_id,
        'post_title' => $post->post_title,
        'template_type' => is_string($template_type) ? $template_type : '',
        'content' => $compact ? el_strip_settings_tree($elements) : el_normalize_output_tree($elements),
        'element_count' => el_count_elements($elements),
    ];
    if ($compact) {
        $payload['hint'] = 'Compact skeleton. To inspect one widget pass element_id:"<id>" (cheap, targeted). Only pass full_dump:true when you really need every widget\'s settings at once (e.g. full page clone or audit).';
    }

    return array_merge($payload, el_document_cache_status($post_id));
}

/**
 * Whether Elementor Pro would serve a cached render for this document (which
 * would mask a programmatic edit until the cache is cleared), mirroring
 * Document::print_elements + get_document_cache: the element-cache feature must
 * be active, the _elementor_element_cache meta present with a future timeout,
 * and its value an array. When served, also returns the expiry (a
 * current_time()-based timestamp, as Elementor stores it) and seconds remaining.
 *
 * @return array{has_element_cache: bool, cache_expires_at?: int, cache_expires_in_seconds?: int}
 */
function el_document_cache_status(int $post_id): array
{
    if (get_option('elementor_element_cache_ttl') === 'disable') {
        return ['has_element_cache' => false];
    }
    $cache = el_decode_cache_meta(get_post_meta($post_id, key: el_element_cache_meta_key(), single: true));
    if ($cache === null) {
        return ['has_element_cache' => false];
    }
    /** @var mixed $raw_timeout */
    $raw_timeout = $cache['timeout'] ?? null;
    $timeout = 0;
    if (is_int($raw_timeout)) {
        $timeout = $raw_timeout;
    }
    if (is_string($raw_timeout) && ctype_digit($raw_timeout)) {
        $timeout = (int) $raw_timeout;
    }
    $now = (int) current_time('timestamp');
    if ($timeout <= $now || !isset($cache['value']) || !is_array($cache['value'])) {
        return ['has_element_cache' => false];
    }
    return [
        'has_element_cache' => true,
        'cache_expires_at' => $timeout,
        'cache_expires_in_seconds' => $timeout - $now,
    ];
}

/**
 * Decode the element-cache meta into an array. Elementor stores it JSON-encoded
 * (Document::update_json_meta); a raw array is accepted too.
 *
 * @param mixed $raw
 * @return array<array-key, mixed>|null
 */
function el_decode_cache_meta($raw): ?array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (is_string($raw) && $raw !== '') {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

/**
 * Count every element in a tree, including nested children.
 *
 * @param list<array<string, mixed>> $elements
 */
function el_count_elements(array $elements): int
{
    $count = 0;
    foreach ($elements as $element) {
        ++$count;
        if (!is_array($element['elements'] ?? null)) {
            continue;
        }

        /** @var list<array<string, mixed>> $children */
        $children = $element['elements'];
        $count += el_count_elements($children);
    }
    return $count;
}

/**
 * Walk an element tree and drop the `settings` field from every node,
 * keeping only the structural fields (id, elType, widgetType, children).
 *
 * @param list<array<string, mixed>> $elements
 * @return list<array<string, mixed>>
 */
function el_strip_settings_tree(array $elements): array
{
    $out = [];
    foreach ($elements as $element) {
        $out[] = el_strip_settings_node($element);
    }
    return $out;
}

/**
 * Walk an element tree and normalize each node's `styles` field for output.
 * When `styles` is present and empty (either `[]` or `{}`), replace it with
 * a `stdClass` so the JSON encoder emits `{}` instead of `[]` — the shape
 * agents should see when they inspect existing content, since the write
 * path expects a keyed map of style IDs. PHP decodes the stored `{}` as an
 * empty list, which mis-signals "array of anonymous styles" to the caller.
 *
 * @param list<array<string, mixed>> $elements
 * @return list<array<string, mixed>>
 */
function el_normalize_output_tree(array $elements): array
{
    $out = [];
    foreach ($elements as $element) {
        $out[] = el_normalize_output_node($element);
    }
    return $out;
}

/**
 * Normalize a single element for output and recurse into its children.
 *
 * @param array<string, mixed> $element
 * @return array<string, mixed>
 */
function el_normalize_output_node(array $element): array
{
    if (array_key_exists('styles', $element)) {
        $element['styles'] = el_normalize_output_styles($element['styles']);
    }

    if (array_key_exists('interactions', $element)) {
        $element['interactions'] = el_normalize_output_interactions($element['interactions']);
    }

    if (is_array($element['elements'] ?? null)) {
        /** @var list<array<string, mixed>> $children */
        $children = $element['elements'];
        $element['elements'] = el_normalize_output_tree($children);
    }

    return $element;
}

/**
 * Map empty `styles` shapes (both `[]` from raw meta and the decoded `{}`
 * which PHP hands back as `[]`) to a `stdClass` instance so JSON encoding
 * produces `{}` — the shape the write path expects. Non-empty arrays are
 * left alone: PHP encodes a keyed array as a JSON object already.
 */
function el_normalize_output_styles(mixed $styles): mixed
{
    if (is_array($styles) && $styles === []) {
        return new \stdClass();
    }
    return $styles;
}

/**
 * Elementor serializes interactions as a JSON string on disk. Leaving that
 * through to the caller forces every downstream tool to JSON.parse one
 * field but not others (e.g. `styles` which is already an array). The
 * asymmetry is a paper cut that compounds: diff tools, inspectors, and
 * edit roundtrips all grow a special case. Decode once here so `get-content`
 * hands back a normal object for atomic interactions, and leave the empty
 * shapes (`""`, `[]`, `null`, already-decoded array) as-is.
 */
function el_normalize_output_interactions(mixed $interactions): mixed
{
    if (!is_string($interactions) || $interactions === '') {
        return $interactions;
    }

    /** @var mixed $decoded */
    $decoded = json_decode($interactions, associative: true);
    if (!is_array($decoded)) {
        return $interactions;
    }
    return $decoded;
}

/**
 * Strip settings from a single element and recurse into its children.
 *
 * @param array<string, mixed> $element
 * @return array<string, mixed>
 */
function el_strip_settings_node(array $element): array
{
    $compact = [];

    foreach (['id', 'elType', 'widgetType'] as $field) {
        if (!array_key_exists($field, $element)) {
            continue;
        }
        $compact[$field] = $element[$field];
    }

    if (is_array($element['elements'] ?? null)) {
        /** @var list<array<string, mixed>> $children */
        $children = $element['elements'];
        $compact['elements'] = el_strip_settings_tree($children);
    }

    return $compact;
}
