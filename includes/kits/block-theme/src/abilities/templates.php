<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockTheme;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

$template_type = [
    'type' => 'string',
    'enum' => ['wp_template', 'wp_template_part'],
    'default' => 'wp_template',
    'description' => 'wp_template for whole-page templates (home, single, page, 404...), wp_template_part for parts such as header and footer.',
];
$template_id = ['type' => 'string', 'minLength' => 3, 'maxLength' => 200, 'description' => 'The full id, "theme//slug", as wppilot/list-templates returns it, for example "twentytwentyfive//header".'];
$can_edit = static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options');
$block_theme_only = static fn(): ?WP_Error => wp_is_block_theme() ? null : new WP_Error('kit_block_theme_not_block_theme', 'The active theme is not a block theme, so it has no site editor templates. Use the theme\'s or page builder\'s own tools.');

wp_register_ability('wppilot/list-templates', [
    'label' => __('List Templates', domain: 'wppilot'),
    'description' => __(
        'Lists the active block theme\'s templates (type wp_template: home, single, page, archive, 404...) or template parts (type wp_template_part: header, footer...), with whether each has been customised in the site editor (source "custom") or still comes from the theme file. Read one with wppilot/get-template.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => ['type' => $template_type], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input = []) use ($block_theme_only): array|WP_Error {
        if ($error = $block_theme_only()) {
            return $error;
        }
        $type = template_type($input['type'] ?? '');
        $list = rest('GET', template_route($type), ['context' => 'edit', 'per_page' => 100]);
        if ($list instanceof WP_Error) {
            return $list;
        }

        return ['type' => $type, 'theme' => get_stylesheet(), 'items' => array_values(array_map(__NAMESPACE__ . '\\template_summary', array_filter($list, 'is_array')))];
    },
    'permission_callback' => $can_edit,
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

wp_register_ability('wppilot/get-template', [
    'label' => __('Get Template', domain: 'wppilot'),
    'description' => __('Reads one template or template part: its block markup, title, and whether it is customised or still the theme file\'s.', domain: 'wppilot'),
    'category' => 'appearance',
    'input_schema' => ['type' => 'object', 'properties' => ['id' => $template_id, 'type' => $template_type], 'required' => ['id'], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input) use ($block_theme_only): array|WP_Error {
        if ($error = $block_theme_only()) {
            return $error;
        }
        $type = template_type($input['type'] ?? '');
        $found = find_template($type, (string) $input['id']);
        if ($found === null) {
            return new WP_Error('kit_block_theme_template_missing', 'No such template. Check the id with wppilot/list-templates (and type for template parts).', ['status' => 404]);
        }

        return template_summary($found) + ['type' => $type, 'content' => raw($found['content'] ?? '')];
    },
    'permission_callback' => $can_edit,
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

wp_register_ability('wppilot/update-template', [
    'label' => __('Update Template', domain: 'wppilot'),
    'description' => __(
        'Saves new block markup for a template or template part, as the site editor does. A theme-file template gets its customised copy on the first save; the theme file itself is never changed, and wppilot/revert-template goes back to it. content is the complete markup of the template (read it first with wppilot/get-template and change what is needed). WordPress filters the HTML for users who may not post unfiltered HTML. Can be undone from the change log.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => $template_id,
            'type' => $template_type,
            'content' => ['type' => 'string', 'description' => 'The full block markup.'],
            'title' => ['type' => 'string', 'maxLength' => 200],
        ],
        'required' => ['id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input) use ($block_theme_only): array|WP_Error {
        if ($error = $block_theme_only()) {
            return $error;
        }
        $type = template_type($input['type'] ?? '');
        $id = (string) $input['id'];
        $content = valid_markup($input['content'] ?? null);
        if ($content instanceof WP_Error) {
            return $content;
        }
        $before = find_template($type, $id);
        if ($before === null) {
            return new WP_Error('kit_block_theme_template_missing', 'No such template. Check the id with wppilot/list-templates.', ['status' => 404]);
        }
        $was_custom = ($before['source'] ?? '') === 'custom';
        $params = ['content' => $content];
        if (isset($input['title'])) {
            $params['title'] = sanitize_text_field((string) $input['title']);
        }
        $saved = rest('POST', template_route($type, $id), $params);
        if ($saved instanceof WP_Error) {
            return $saved;
        }

        return template_summary($saved) + [
            'type' => $type,
            'created_customisation' => !$was_custom,
            // For the change log: the post a first save creates is what undo removes.
            'created_post_id' => $was_custom ? 0 : (int) ($saved['wp_id'] ?? 0),
            'post_type' => $type,
        ];
    },
    'permission_callback' => $can_edit,
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);

wp_register_ability('wppilot/revert-template', [
    'label' => __('Revert Template', domain: 'wppilot'),
    'description' => __(
        'Throws away the site editor customisation of a template or template part and goes back to the theme file\'s version, like "Reset" in the site editor. Only for templates that have a theme file (has_theme_file). Destructive: needs confirm. The customised version is kept in the change log, so it can be put back.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['id' => $template_id, 'type' => $template_type, 'confirm' => ['type' => 'boolean']],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input) use ($block_theme_only): array|WP_Error {
        if ($error = $block_theme_only()) {
            return $error;
        }
        $guard = Runtime\confirm_guard('wppilot/revert-template', $input);
        if ($guard instanceof WP_Error) {
            return $guard;
        }
        $type = template_type($input['type'] ?? '');
        $id = (string) $input['id'];
        $found = find_template($type, $id);
        if ($found === null) {
            return new WP_Error('kit_block_theme_template_missing', 'No such template.', ['status' => 404]);
        }
        if (($found['source'] ?? '') !== 'custom') {
            return ['id' => $id, 'reverted' => false, 'reason' => 'This template is not customised; it already is the theme file\'s version.'];
        }
        if (!($found['has_theme_file'] ?? false)) {
            return new WP_Error('kit_block_theme_no_theme_file', 'This template was created in the site editor and has no theme file to go back to. Reverting would delete it.', ['status' => 409]);
        }
        $deleted = rest('DELETE', template_route($type, $id), ['force' => true]);
        if ($deleted instanceof WP_Error) {
            return $deleted;
        }

        return ['id' => $id, 'type' => $type, 'reverted' => true];
    },
    'permission_callback' => $can_edit,
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true]],
]);
