<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Skills module entry point.
 *
 * Loads the submodules (CPT, parser, sources, catalog, prompts, admin, abilities)
 * and wires the top-level WordPress hooks. The module is self-contained: every
 * piece of code it needs lives under `includes/skills/`, so it can be lifted as
 * a unit when a portable extraction is needed.
 */

namespace WPPilot\Skills;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/cpt.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/built-in.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/notices.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/abilities/skill-get.php';
require_once __DIR__ . '/abilities/skill-write.php';
require_once __DIR__ . '/abilities/skill-edit.php';
require_once __DIR__ . '/abilities/skill-delete.php';

add_action('init', __NAMESPACE__ . '\\Cpt\\register');
// Skills is registered as a submenu under the GoBuildWP top-level menu;
// run after GoBuildWP's main menu callback (priority 10) attaches the
// parent slug `wppilot-connect`.
add_action('admin_menu', __NAMESPACE__ . '\\Admin\\register_menu', priority: 40);
add_action('admin_init', __NAMESPACE__ . '\\Admin\\register_post_handlers');
add_action('admin_notices', __NAMESPACE__ . '\\Notices\\render');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\Admin\\enqueue_assets');

add_filter('wppilot_skill_lookup_sources', __NAMESPACE__ . '\\BuiltIn\\register');
add_filter('wppilot_discover_abilities_instructions', __NAMESPACE__ . '\\Catalog\\inject', priority: 10);

// The 'skill' category must register in the categories phase; wp_register_ability_category()
// is only honored during wp_abilities_api_categories_init, so registering it on
// wp_abilities_api_init (as before) was silently dropped and every ability below it —
// declaring 'category' => 'skill' — was rejected. Match the base plugin and the design module.
// MCP prompt-mode skill abilities and the canonical skill-get must register
// after any pre-existing owner so we can save a reference and delegate when
// our lookup misses (priority 999). See abilities/skill-get.php for details.
// WordPress older than 6.9 has no Abilities API and must receive no Ability hooks.
if (\wppilot_wordpress_abilities_supported()) {
    add_action('wp_abilities_api_categories_init', __NAMESPACE__ . '\\Abilities\\register_categories', priority: 5);
    add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Prompts\\register_dynamic_abilities', priority: 500);
    add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillGet\\register', priority: 999);
    add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillWrite\\register', priority: 999);
    add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillEdit\\register', priority: 999);
    add_action('wp_abilities_api_init', __NAMESPACE__ . '\\Abilities\\SkillDelete\\register', priority: 999);
}
