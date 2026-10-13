<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * content-audit: broken links, orphans, thin content, missing SEO meta and JSON-LD problems.
 *
 * Read-only. The job kind is registered on every request, not only when an audit starts: the
 * runner's cron tick arrives in a later request and must find the step callback there.
 */
require_once __DIR__ . '/src/Source.php';
require_once __DIR__ . '/src/WpSource.php';
require_once __DIR__ . '/src/Links.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Fixes.php';
require_once __DIR__ . '/src/Auditor.php';
require_once __DIR__ . '/src/audit.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/audit-content.php',
        __DIR__ . '/src/abilities/audit-content-status.php',
    ],
    'boot' => static function (Host $host): void {
        $host->jobs()->register(JOB_KIND, __NAMESPACE__ . '\step');
    },
];
