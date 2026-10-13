<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Preview before write.
 *
 * Self-contained, modelled on includes/skills/ and includes/design/, so the
 * whole feature can be read — or lifted — as one directory. The abilities
 * themselves live with the other abilities in includes/abilities/preview.php,
 * because that is where wppilot_register_builtin_abilities() looks.
 */

require_once __DIR__ . '/diff.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/projectors.php';
require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/gate.php';

if (is_admin()) {
    require_once __DIR__ . '/admin.php';

    // Priority 35 sits between Instructions (30) and Skills (40): the screen
    // belongs with the agent-facing tools rather than at the end of the rail.
    add_action('admin_menu', __NAMESPACE__ . '\\Admin\\register_menu', priority: 35);
    add_action('admin_init', __NAMESPACE__ . '\\Admin\\register_post_handlers');
    add_action('admin_notices', __NAMESPACE__ . '\\Admin\\render_notice');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin\\enqueue_assets');
    add_filter('wppilot_nav_map', __NAMESPACE__ . '\\Admin\\register_nav');
}

add_filter('wppilot_settings_sections', __NAMESPACE__ . '\\Gate\\register_setting');

// Two attachment points: the gate pipeline's filter, which the REST paths, the
// modern MCP transport, Chat and Pro's approval replay all fire, and the legacy
// MCP adapter's own envelope filter. The rule stands aside for Chat, which the
// setting's own label says.
add_filter('wppilot_pre_ability_execute', __NAMESPACE__ . '\\Gate\\filter_pre_ability_execute', priority: 8, accepted_args: 3);
add_filter('mcp_adapter_pre_tool_call', __NAMESPACE__ . '\\Gate\\filter_pre_mcp_tool_call', priority: 8, accepted_args: 2);
