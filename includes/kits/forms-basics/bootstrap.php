<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\FormsBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * forms-basics: read the forms and (redacted) entries of WPForms, Contact Form 7, Gravity Forms
 * and Forminator.
 *
 * Returned to the kit loader, which registers the ability files inside wp_abilities_api_init.
 * Only the files of the form plugins active on this request are listed, each checked against the
 * same version floor GoBuildWP Pro uses for it, so an agent sees tools for the plugins the site has.
 * Skipped when none is active.
 */
require_once __DIR__ . '/src/redaction.php';
require_once __DIR__ . '/src/entries.php';
require_once __DIR__ . '/src/wpforms.php';
require_once __DIR__ . '/src/cf7.php';
require_once __DIR__ . '/src/gravityforms.php';
require_once __DIR__ . '/src/forminator.php';

// A closure, so the list does not land in the scope of whatever function required this file.
return (static function (): array {
    $vendors = [
        'wpforms' => wpforms_available(),
        'cf7' => cf7_available(),
        'gravityforms' => gf_available(),
        'forminator' => forminator_installed(),
    ];
    $files = [];
    foreach (array_keys(array_filter($vendors)) as $vendor) {
        $files[] = __DIR__ . '/src/abilities/' . $vendor . '.php';
    }
    if ($files === []) {
        return ['skip' => 'none of WPForms 1.8+, Contact Form 7 5.8+, Gravity Forms 2.7+ or Forminator is active'];
    }
    return ['ability_files' => $files];
})();
