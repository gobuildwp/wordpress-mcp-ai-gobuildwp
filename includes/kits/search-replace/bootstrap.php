<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * search-replace: find and replace across posts and post meta from a reviewed, stored plan, with
 * one undoable ledger row per post.
 *
 * The job kind is registered on every request, not only when an apply queues one: the Runner's
 * cron tick runs in a later request, and a kind nobody registered there leaves the job waiting.
 */
require_once __DIR__ . '/src/engine.php';
require_once __DIR__ . '/src/plans.php';
require_once __DIR__ . '/src/scan.php';
require_once __DIR__ . '/src/apply.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/search-replace-preview.php',
        __DIR__ . '/src/abilities/search-replace-apply.php',
        __DIR__ . '/src/abilities/search-replace-status.php',
        __DIR__ . '/src/abilities/search-replace-cancel.php',
    ],
    'boot' => static function (Host $host): void {
        $host->jobs()->register(JOB_KIND, static fn(array $payload, array $state): array => job_step($payload, $state));
    },
];
