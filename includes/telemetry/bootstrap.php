<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Telemetry;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/send.php';

/**
 * Wire up anonymous usage reporting.
 *
 * Opt-in: nothing is sent until an administrator switches it on under GoBuildWP >
 * Settings. There is deliberately no first-run notice — a plugin that reports
 * only when asked has nothing to disclose on activation, and the settings row
 * and the privacy policy text carry the full description of what is sent.
 *
 * This entire directory is deleted from the wordpress.org build by
 * scripts/package.sh, and wppilot.php guards the require with file_exists() so
 * that removal is safe — the same arrangement includes/updater.php uses, for
 * the same reason.
 */

add_action(CRON_HOOK, __NAMESPACE__ . '\send');
add_filter('wppilot_settings_sections', __NAMESPACE__ . '\register_setting');

/**
 * Keep the schedule in step with the switch.
 *
 * Two directions, and the second is why this runs on every admin request rather
 * than on activation:
 *
 * - On: repair a schedule that went missing. A site can lose its cron entries
 *   to a database restore or a migration plugin, and an install that updates
 *   into this version never runs activation.
 * - Off: clear an event that outlived its reason. 1.6.0 reported by default and
 *   scheduled on activation, so a site updating to 1.6.1 without ever having
 *   chosen arrives here with a daily event and no consent behind it. send()
 *   already refuses to report, but leaving a dead event on the schedule is its
 *   own small misstatement of what the plugin is doing.
 *
 * Never turns reporting on: enabled() is false until someone stores a decision.
 * Checked on admin requests only — this must not cost a front-end page view
 * anything.
 */
add_action('admin_init', static function (): void {
    if (enabled()) {
        schedule();
        return;
    }

    if (wp_next_scheduled(CRON_HOOK) !== false) {
        unschedule();
    }
});
