<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Clear Elementor's per-document rendered-element cache.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-clear-document-cache', [
    'label' => __('Clear Elementor Document Cache', domain: 'wppilot'),
    'description' => __(
        'Clears Elementor Pro\'s rendered-element cache (the _elementor_element_cache post meta) for one or more documents, so the next front-end view rebuilds the HTML from the live element tree and dynamic content instead of serving stale cached output. Elementor checks this cache before applying render filters, so a template cached in one context can otherwise keep showing on other pages. Pass post_ids as the document ids to clear, including any nested template ids (each template caches independently of its parent). Use after editing a template or its dynamic bindings when a change is not appearing on the front end. Elementor rebuilds the cache on the next render, so this is non-destructive.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'minItems' => 1,
                'description' => 'Document ids whose element cache to clear. Include nested template ids — they cache independently of the parent document.',
            ],
        ],
        'required' => ['post_ids'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'cleared_count' => [
                'type' => 'integer',
                'description' => 'How many documents actually had a cache that was cleared.',
            ],
            'results' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'exists' => ['type' => 'boolean'],
                        'had_cache' => ['type' => 'boolean'],
                        'cleared' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ],
        'required' => ['cleared_count', 'results'],
    ],
    'execute_callback' => 'WPPilot\\Elementor\\elementor_clear_document_cache',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * The element-cache post-meta key. Resolved from Elementor's own
 * Document::CACHE_META_KEY constant when available (so a future rename is
 * followed), with the documented literal as a fallback.
 */
function el_element_cache_meta_key(): string
{
    $class = 'Elementor\\Core\\Base\\Document';
    if (class_exists($class)) {
        /** @var mixed $key */
        $key = constant($class . '::CACHE_META_KEY');
        if (is_string($key) && $key !== '') {
            return $key;
        }
    }
    return '_elementor_element_cache';
}

/**
 * @param array<string, mixed> $input
 * @return array{cleared_count: int, results: list<array{post_id: int, exists: bool, had_cache: bool, cleared: bool}>}
 */
function elementor_clear_document_cache(array $input): array
{
    $meta_key = el_element_cache_meta_key();

    /** @var mixed $raw_ids */
    $raw_ids = $input['post_ids'] ?? [];
    $ids = is_array($raw_ids) ? $raw_ids : [];

    /** @var list<array{post_id: int, exists: bool, had_cache: bool, cleared: bool}> $results */
    $results = [];
    $cleared = 0;
    /** @var mixed $raw_id */
    foreach ($ids as $raw_id) {
        $post_id = is_int($raw_id) ? $raw_id : (int) $raw_id;
        $exists = get_post($post_id) !== null;
        $had_cache = $exists && metadata_exists(meta_type: 'post', object_id: $post_id, meta_key: $meta_key);
        $did = $had_cache && delete_post_meta($post_id, meta_key: $meta_key);
        if ($did) {
            $cleared++;
        }
        $results[] = ['post_id' => $post_id, 'exists' => $exists, 'had_cache' => $had_cache, 'cleared' => $did];
    }

    return ['cleared_count' => $cleared, 'results' => $results];
}
