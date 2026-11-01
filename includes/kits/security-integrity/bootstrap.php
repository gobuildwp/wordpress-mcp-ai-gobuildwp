<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * security-integrity: file integrity and database security scans that run as background jobs.
 *
 * Both job kinds are registered on every request, because the Runner's cron tick runs in a later
 * request and a kind nobody registered there leaves the job waiting. Registering is all that
 * happens here: the scan engine is loaded only when a step or an ability actually runs, so a
 * page view never parses it.
 */
require_once __DIR__ . '/src/kinds.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/integrity-scan-start.php',
        __DIR__ . '/src/abilities/integrity-scan-status.php',
        __DIR__ . '/src/abilities/integrity-scan-results.php',
        __DIR__ . '/src/abilities/integrity-scan-cancel.php',
        __DIR__ . '/src/abilities/db-security-scan-start.php',
        __DIR__ . '/src/abilities/db-security-scan-status.php',
        __DIR__ . '/src/abilities/db-security-scan-results.php',
        __DIR__ . '/src/abilities/db-security-scan-cancel.php',
        __DIR__ . '/src/abilities/security-fix-apply.php',
        __DIR__ . '/src/abilities/security-fix-undo.php',
        __DIR__ . '/src/abilities/security-fix-list.php',
    ],
    'boot' => static function (Host $host): void {
        $host->jobs()->register(KIND_FILES, static function (array $payload, array $state): array {
            require_once __DIR__ . '/src/load.php';
            return step(KIND_FILES, $payload, $state);
        });
        $host->jobs()->register(KIND_DB, static function (array $payload, array $state): array {
            require_once __DIR__ . '/src/load.php';
            return step(KIND_DB, $payload, $state);
        });
    },
];
