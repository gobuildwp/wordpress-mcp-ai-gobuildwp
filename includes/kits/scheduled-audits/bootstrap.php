<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * scheduled-audits: read-only audits the site runs on a schedule, with no agent connected.
 *
 * Returned to the kit loader, which boots it on every request (WP-Cron's included) and registers
 * its ability files inside wp_abilities_api_init. Boot registers the cron callbacks and the undo
 * for a routine edit, so a run queued on one request is advanced on the next and a change
 * recorded earlier can be undone.
 *
 * The host may already run routines from another copy of this feature over the same stored
 * routines — inside GoBuildWP, a GoBuildWP Pro release that still carries its own. Two copies would
 * both answer every cron tick, running each audit twice and sending every email twice, so when
 * the host's `routines-elsewhere` extension point names that owner, this copy does not load.
 */
/** @var mixed $elsewhere */
$elsewhere = Runtime\host()->extension('routines-elsewhere');
if (is_string($elsewhere) && $elsewhere !== '') {
    return ['skip' => $elsewhere];
}

require_once __DIR__ . '/src/routines.php';
require_once __DIR__ . '/src/schedule.php';
require_once __DIR__ . '/src/runner.php';
require_once __DIR__ . '/src/report.php';
require_once __DIR__ . '/src/manage.php';
require_once __DIR__ . '/src/hooks.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/routines-list.php',
        __DIR__ . '/src/abilities/routines-report.php',
        __DIR__ . '/src/abilities/routines-save.php',
        __DIR__ . '/src/abilities/routines-delete.php',
        __DIR__ . '/src/abilities/routines-run-now.php',
    ],
    'boot' => static function (Host $host): void {
        register_hooks($host);
    },
];
