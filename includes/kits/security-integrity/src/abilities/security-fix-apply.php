<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-fix-apply', [
    'label' => __('Apply Security Fixes', domain: 'wppilot'),
    'description' => __(
        'Fixes what a security scan reported, 1 to 25 items per call, each undoable with security-fix-undo. Actions: quarantine_file (target = file path relative to the WordPress folder; the file is moved into a protected quarantine folder, never deleted; WordPress core files, wp-config.php and files WordPress needs to run are refused), repair_core_file (target = core file path; replaced with the official WordPress.org copy for this version after its checksum is verified; a missing core file is restored), demote_user (target = user id; an administrator becomes a subscriber and is signed out everywhere; the last administrator and the calling account are refused), unpublish_post (target = post id; set to draft), repair_plugin_file (target = file path inside a WordPress.org plugin, wp-content/plugins/{folder}/...; replaced with the official copy for the installed version after its sha256 is checked against the WordPress.org list; a missing listed file is restored), clean_file (target = path of an infected non-core file; a WordPress.org plugin or theme file is replaced with the official copy, otherwise injected PHP lines and blocks are cut out and the rest must parse and scan clean; a file that was only the injection is quarantined; detail.mode is replaced_official, stripped (with lines_removed) or quarantined; refused with cannot_isolate when the injection cannot be separated), clean_option (target = option name; script and iframe elements are stripped from its value, or the option is deleted when it holds a code-eval or base64 decoder payload; core settings and GoBuildWP options are refused), remove_cron (target = scheduled hook name; its events whose arguments carry a URL on another site are removed, or all its events when nothing handles the hook), clean_post (target = post id; the script and iframe elements the database scan flags are removed from its content). Each item is applied or refused on its own and the result says which, with a fix_id for every applied one. Destructive: needs confirm=true.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 25,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['quarantine_file', 'repair_core_file', 'demote_user', 'unpublish_post', 'repair_plugin_file', 'clean_file', 'clean_option', 'remove_cron', 'clean_post']],
                        'target' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 400, 'description' => 'File path relative to the WordPress folder (quarantine_file, repair_core_file, repair_plugin_file, clean_file), a user id (demote_user), a post id (unpublish_post, clean_post), an option name (clean_option) or a scheduled hook name (remove_cron).'],
                    ],
                    'required' => ['action', 'target'],
                    'additionalProperties' => false,
                ],
            ],
            'reason' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Why, kept with each fix (for example the finding it resolves).'],
            'confirm' => ['type' => 'boolean', 'description' => 'Must be true: the person approved these fixes.'],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'applied' => ['type' => 'integer'],
            'refused' => ['type' => 'integer'],
            'results' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return apply_fixes($input);
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
