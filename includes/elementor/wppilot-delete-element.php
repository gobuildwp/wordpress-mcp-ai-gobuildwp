<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Remove an element from an Elementor document.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-delete-element', [
    'label' => __('Delete Elementor Element', domain: 'wppilot'),
    'description' => __(
        'Deletes an element (widget or container) and all its children from an Elementor document. Permanent — there is no trash for Elementor elements.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_delete_element',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string}
 */
function elementor_delete_element(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['success' => false, 'error' => "Post {$post_id} not found."];
    }

    $element_id = (string) ($input['element_id'] ?? '');
    if ($element_id === '') {
        return ['success' => false, 'error' => 'Parameter "element_id" is required.'];
    }

    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    if (!el_remove($elements, $element_id)) {
        return ['success' => false, 'error' => "Element '{$element_id}' not found on post {$post_id}."];
    }

    $result = el_write_page($post_id, $elements);
    if (is_wp_error($result)) {
        return ['success' => false, 'error' => $result->get_error_message()];
    }

    return ['success' => true];
}
