<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteKitSharing;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

if (Runtime\unclaimed('wppilot/site-kit-enable-sharing')) {
    wp_register_ability('wppilot/site-kit-enable-sharing', [
        'label' => __('Enable Site Kit Dashboard Sharing', domain: 'wppilot'),
        'description' => __(
            'Turns on Site Kit by Google\'s Dashboard sharing (read-only) for Search Console, Analytics 4 and PageSpeed Insights to the Administrator role, through Site Kit\'s own sharing settings route, exactly as Site Kit > Dashboard sharing does. Administrators who are not signed in to Site Kit with Google can then read those modules through the owner\'s account, which is what lets Site Kit reads and PageSpeed checks made through this plugin work for them. Never reads or writes a Google token, and never changes who may manage sharing. Site Kit only accepts the change from an administrator signed in to Site Kit with Google who owns the module (any signed-in admin for PageSpeed Insights); modules this user may not change are reported as not_permitted with their owner. Modules not connected to Google are skipped. Undo with wppilot/rollback-change.',
            domain: 'wppilot',
        ),
        'category' => 'site-kit',
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'modules' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => MODULES],
                    'minItems' => 1,
                    'uniqueItems' => true,
                    'description' => 'Which modules to share. Defaults to all three.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Required: this lets every Administrator read the owner\'s Google data for these modules.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'changed' => ['type' => 'boolean'],
                'role' => ['type' => 'string'],
                'modules' => ['type' => 'object'],
                'new_owner_ids' => ['type' => 'object'],
                'next' => ['type' => 'string'],
            ],
            'required' => ['changed', 'role', 'modules'],
        ],
        'execute_callback' => static fn(array $input = []): array|\WP_Error => enable_sharing($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Offer this once, when a Site Kit read or PageSpeed check names it in `fix`, and run it only after the user agrees (confirm=true). If they decline, do not offer it again in this conversation. not_permitted means only the named owner can do it: tell the user who, rather than retrying.',
                'readonly' => false,
                'destructive' => true,
                'idempotent' => true,
            ],
        ],
    ]);
    Runtime\host()->ledger()->capture_for('wppilot/site-kit-enable-sharing', static fn(array $input): array => snapshot());
}
