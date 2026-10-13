<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteIssues;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * site-issues: a log of the PHP fatal errors this site's requests hit, recorded from WordPress's
 * fatal error handler, read together with paused extensions, failed backups and rolled-back
 * updates.
 *
 * The recorder is hooked here, on every request the kits boot in, not with the abilities (which
 * register only when the Abilities API collects them): the error has to be caught in the request
 * that hits it. Other kits read the log through the ERRORS_FILTER, so a copy without this kit
 * simply has nothing to report.
 */
require_once __DIR__ . '/src/errors.php';
require_once __DIR__ . '/src/issues.php';

register_recorder();

if (has_filter(ERRORS_FILTER, __NAMESPACE__ . '\\errors_filter') === false) {
    add_filter(ERRORS_FILTER, __NAMESPACE__ . '\\errors_filter', 10, 2);
}

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/site-issues.php',
    ],
];
