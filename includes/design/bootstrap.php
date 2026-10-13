<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Design System module entry point. Self-contained under includes/design/,
 * modeled on includes/skills/ so it can be lifted as a unit.
 */

namespace WPPilot\Design;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/grammars.php';
require_once __DIR__ . '/preflight.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/cpt.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/revisions.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/contrast.php';
require_once __DIR__ . '/distinct.php';
require_once __DIR__ . '/typefaces.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/generate.php';
require_once __DIR__ . '/spec.php';
require_once __DIR__ . '/rendered.php';
require_once __DIR__ . '/adopt.php';
require_once __DIR__ . '/examples.php';
require_once __DIR__ . '/capture.php';
require_once __DIR__ . '/visual-runtime.php';
\WPPilot\Design\VisualRuntime\boot();
require_once __DIR__ . '/abilities/categories.php';
require_once __DIR__ . '/abilities/list-design-library.php';
require_once __DIR__ . '/abilities/list-layout-grammars.php';
require_once __DIR__ . '/abilities/list-typefaces.php';
require_once __DIR__ . '/abilities/design-spec.php';
require_once __DIR__ . '/abilities/generate-design.php';
require_once __DIR__ . '/abilities/compare-references.php';
require_once __DIR__ . '/abilities/check-composition.php';
require_once __DIR__ . '/abilities/get-active-design.php';
require_once __DIR__ . '/abilities/activate-design.php';
require_once __DIR__ . '/abilities/save-design.php';
require_once __DIR__ . '/abilities/check-design.php';
require_once __DIR__ . '/abilities/check-contrast.php';
require_once __DIR__ . '/abilities/verify-rendered-page.php';
require_once __DIR__ . '/abilities/get-page-view-link.php';
require_once __DIR__ . '/abilities/captures.php';
require_once __DIR__ . '/abilities/capture-page.php';
require_once __DIR__ . '/abilities/adopt-design.php';
require_once __DIR__ . '/abilities/list-design-examples.php';
require_once __DIR__ . '/abilities/get-design.php';
require_once __DIR__ . '/abilities/delete-design.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/notices.php';

add_action('init', __NAMESPACE__ . '\\Cpt\\register');
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\register_menu', priority: 11);
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\reorder_submenu', priority: 999);
add_action('admin_init', __NAMESPACE__ . '\\Admin\\register_post_handlers');
add_action('admin_notices', __NAMESPACE__ . '\\Notices\\render');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin\\enqueue_assets');
add_filter(
    'wp_' . Cpt\POST_TYPE . '_revisions_to_keep',
    __NAMESPACE__ . '\\Revisions\\limit',
    priority: 10,
    accepted_args: 2,
);
add_action('wp_abilities_api_categories_init', __NAMESPACE__ . '\\Abilities\\register_category');
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\ListLibrary\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\GetActive\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Activate\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Save\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Check\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Contrast\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\VerifyRendered\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\ViewLink\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Captures\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\CapturePage\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Adopt\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\Abilities\Grammars\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\Abilities\Typefaces\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\Abilities\DesignSpec\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\Abilities\GenerateDesign\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\Abilities\CompareReferences\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\Abilities\CheckComposition\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Examples\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Get\\register', priority: 999);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\Delete\\register', priority: 999);

// The design gate sits with the other write-path checks: rate limiting at 6,
// preview at 8, Pro's approval queue at 9. Design runs at 7, between the limiter
// and preview — a write that is off-direction should be refused before anybody
// is asked to review a diff of it.
add_filter('wppilot_settings_sections', __NAMESPACE__ . '\Gate\register_setting');
add_filter('wppilot_pre_ability_execute', __NAMESPACE__ . '\Gate\filter_pre_ability_execute', priority: 7, accepted_args: 3);
add_filter('mcp_adapter_pre_tool_call', __NAMESPACE__ . '\Gate\filter_pre_mcp_tool_call', priority: 7, accepted_args: 2);

// Priority 11, just after the skills catalogue at 10: an agent should read what
// the site can do before it reads what the site looks like.
add_filter('wppilot_discover_abilities_instructions', __NAMESPACE__ . '\Context\inject', priority: 11);
