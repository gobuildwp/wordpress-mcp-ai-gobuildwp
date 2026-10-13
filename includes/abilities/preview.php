<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\Preview;

use WP_Error;
use WPPilot\Preview;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/preview-ability', [
    'label' => __('Preview Ability', domain: 'wppilot'),
    'description' => __(
        'Computes what another ability would change, without changing anything. Returns a structured diff plus a URL a person can open in wp-admin to review it and apply or discard it. Nothing is written by this call.',
        domain: 'wppilot',
    ),
    'category' => 'preview',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'ability_name' => [
                'type' => 'string',
                'description' => 'The ability whose effect you want to see, e.g. "wppilot/update-post".',
            ],
            'input' => [
                'type' => 'object',
                'description' => 'The exact input you would pass to that ability.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['ability_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'preview_id' => ['type' => 'string'],
            'preview_url' => ['type' => 'string'],
            'supported' => ['type' => 'boolean'],
            'would_fail' => ['type' => 'boolean'],
            'reason' => ['type' => 'string'],
            'diff' => ['type' => ['object', 'null']],
            'target' => ['type' => 'object'],
            'side_effects' => ['type' => 'array', 'items' => ['type' => 'string']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            'expires_at' => ['type' => 'string'],
            'user_instruction' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\execute_preview',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => [
            'public' => true,
            // Links the MCP Apps preview card (includes/mcp/apps.php, Apps\preview_tool_meta()), which a
            // host that supports the extension renders beside the result, with an Apply button.
            '_meta' => [
                'ui' => ['resourceUri' => 'ui://wppilot/preview-card'],
                'ui/resourceUri' => 'ui://wppilot/preview-card',
            ],
        ],
        'annotations' => [
            'instructions' => 'Call this before a write the user has not explicitly approved, especially a delete. '
                . 'It writes nothing: it computes the before and after state and returns the difference. Show the '
                . 'user the diff, or hand them preview_url and let them review it in wp-admin. When they approve, '
                . 'call wppilot/apply-preview with the preview_id — do not re-send the original write, because the '
                . 'preview is what they agreed to. If supported is false, the reason says why and you should run the '
                . 'ability directly instead. If would_fail is true, the call would be refused and the error explains '
                . 'why, so fix the input rather than retrying.',
            // The site's own content is not changed by a preview, which is what
            // readonly means to a caller. It does store a short-lived bounded
            // record so a person can review the diff later; that is deliberate,
            // and capped by MAX_LIVE and a TTL in includes/preview/store.php.
            'readonly' => true,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

wp_register_ability('wppilot/apply-preview', [
    'label' => __('Apply Preview', domain: 'wppilot'),
    'description' => __(
        'Applies a diff previously produced by wppilot/preview-ability, after checking the target has not changed since. Refuses if it has, so what was approved is what runs.',
        domain: 'wppilot',
    ),
    'category' => 'preview',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'preview_id' => ['type' => 'string', 'description' => 'The preview_id returned by wppilot/preview-ability.'],
            'confirm' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Required when the underlying ability is destructive or critical. Set true only after the user has explicitly approved this specific change.',
            ],
        ],
        'required' => ['preview_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'preview_id' => ['type' => 'string'],
            'applied' => ['type' => 'boolean'],
            'ability' => ['type' => 'string'],
            'result' => ['type' => ['object', 'array', 'string', 'number', 'boolean', 'null']],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\execute_apply',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Runs the write the preview described. The underlying ability is re-checked against the '
                . 'safety profile and its own confirmation requirement, so this is not a way around either. If the '
                . 'target changed since the preview was made, this refuses with wppilot_preview_drifted and writes '
                . 'nothing — re-run wppilot/preview-ability and show the user the new diff rather than forcing it '
                . 'through, because their approval described the old state.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function execute_preview(array $input): array|WP_Error
{
    /** @var array<string, mixed> $ability_input */
    $ability_input = is_array($input['input'] ?? null) ? $input['input'] : [];

    return Preview\build((string) ($input['ability_name'] ?? ''), $ability_input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function execute_apply(array $input): array|WP_Error
{
    return Preview\apply((string) ($input['preview_id'] ?? ''), ($input['confirm'] ?? false) === true);
}
