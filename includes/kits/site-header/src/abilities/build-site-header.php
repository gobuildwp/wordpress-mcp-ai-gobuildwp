<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file is require()d inside the kit runtime's register_abilities(), so its variables are local to that function, not globals.

namespace WPPilot\Kits\SiteHeader;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

$by_language = static fn(array $item, string $what): array => [
    'type' => 'object',
    'additionalProperties' => $item,
    'description' => 'Per language, when a language needs its own ' . $what . ': {"ru": ...}. Languages not listed use the shared one.',
];
$tree = ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Elementor elements, in the format Elementor stores them: a container at the top (elType "container"), widgets with widgetType and settings, children in "elements". Any widgets, containers and styles.'];

wp_register_ability('wppilot/build-site-header', [
    'label' => __('Build Site Header', domain: 'wppilot'),
    'description' => __(
        'Saves the site-wide header YOU design and checks it. You are the designer, as for the rest of the website: there is no template. 1) Call with dry_run: true and no design: it returns the site\'s facts (title, tagline, logo, colours and fonts from the Elementor kit or theme.json and the logo, the home page hero, each language\'s menu, whether there is a shop) and building_blocks: settings for the menu, the language switcher and the cart that are known to render well. 2) Design a header that fits this brand - its colours, type and tone - and pass it as elementor.elements (Elementor Pro: Elementor elements, in the format Elementor stores them) or block_markup (block themes: the header template part\'s blocks), with dry_run: true: it is checked before anything is saved. Use the tokens {{menu}} (each language\'s menu), {{language_switcher}} (a styled language dropdown, never a bare list), {{navigation_ref}} (block themes), {{home_url}}, {{site_title}} and {{label:key}} (per-language text from labels); one design then serves every language, or pass elements_by_language for a language that needs its own. 3) Build with confirm: true. Elementor Pro: one Theme Builder header per language on every page (swapped in by language), the headers that had display conditions kept with their conditions removed. Block themes: the header template part, with one translated part per Polylang language and a navigation per language. It then reads each language\'s home page as a visitor and reports objective problems - raw language list, a menu with no menu button for phones, cart without its icon, text below WCAG AA, missing accessible names, broken images and links - each with the element id and what to change; on an error it restores the previous header unless keep_on_fail is true. It returns probe URLs: open them in a browser at 1440, 768 and 390 px and read window.siteHeaderProbe for what only a layout shows (menu rows, wrapping labels, overlaps, horizontal scroll, header height, the menu button opening, computed contrast). Fix your design from the findings and build again; check_only: true re-checks the served header without writing. Undo restores the previous header.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'builder' => ['type' => 'string', 'enum' => ['auto', 'elementor', 'block-theme'], 'default' => 'auto', 'description' => 'auto picks Elementor Pro when it is active, else the block theme.'],
            'elementor' => [
                'type' => 'object',
                'properties' => [
                    'elements' => $tree,
                    'elements_by_language' => $by_language($tree, 'design'),
                ],
                'additionalProperties' => false,
                'description' => 'The header design for Elementor Pro.',
            ],
            'block_markup' => ['type' => 'string', 'maxLength' => 200000, 'description' => 'The header design for a block theme: the header template part\'s block markup. Put the navigation in as <!-- wp:navigation {"ref":"{{navigation_ref}}","overlayMenu":"mobile"} /-->.'],
            'block_markup_by_language' => $by_language(['type' => 'string', 'maxLength' => 200000], 'markup'),
            'labels' => [
                'type' => 'object',
                'additionalProperties' => ['type' => ['string', 'object']],
                'description' => 'Texts for {{label:key}}, the same everywhere or per language: {"cta": {"en": "Order online", "ru": "Заказать"}}. A language without its own uses the default language\'s.',
            ],
            'logo_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'The logo the facts report; 0 or omitted is the site logo (Customizer).'],
            'menu' => ['type' => ['integer', 'string'], 'description' => 'Menu ID or slug for {{menu}} on a single-language site. Defaults to the main (or longest) menu.'],
            'menus' => ['type' => 'object', 'additionalProperties' => ['type' => ['integer', 'string']], 'description' => 'Per language: {"en": 63, "ru": 64}. Defaults to the menu whose name ends in the language code, such as "Main menu (EN)".'],
            'show_language_switcher' => ['type' => 'string', 'enum' => ['auto', 'no'], 'default' => 'auto', 'description' => 'Block themes: auto puts Polylang\'s switcher last in each navigation when the site has two or more languages.'],
            'keep_on_fail' => ['type' => 'boolean', 'default' => false, 'description' => 'Keep the new header even when the served-page checks find errors (to fix it in place). Default: restore the previous header.'],
            'check_only' => ['type' => 'boolean', 'default' => false, 'description' => 'Check the header the site serves now, without writing; returns findings and probe URLs.'],
            'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Without a design: the site facts and building blocks. With one: the checks that need no page, without saving.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Must be true to write: the person approved replacing the site header.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input = []): array|WP_Error {
        // Inside GoBuildWP the gate pipeline enforces confirm; an exported copy has no
        // pipeline, so the kit checks it itself. A dry run and a check write nothing.
        if (empty($input['dry_run']) && empty($input['check_only'])) {
            $guard = Runtime\confirm_guard('wppilot/build-site-header', $input);
            if ($guard instanceof WP_Error) {
                return $guard;
            }
        }
        unset($input['confirm']);

        return build($input);
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options') && current_user_can('publish_pages'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
