<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Cloud: pairing this site with an app.gobuildwp.com workspace.
 *
 * The wire protocol lives in the platform repository (docs/pairing-protocol.md)
 * and both sides implement it exactly; section numbers in comments here refer
 * to it. In short:
 *
 *   pairing.php     §1-§3  begin, return, confirm in wp-admin, and the link option
 *   signing.php     §5     the site's Ed25519 key and signed calls to the Cloud
 *   rest.php        §4     the two routes the Cloud calls with its access token
 *   heartbeat.php   §5     the hourly heartbeat and the schedule that drives it
 *   events.php      §5     the same heartbeat pushed early, with `event`, when a backup, update or extension change happens
 *   updates.php     §4     pending core, plugin and theme updates for the status answer
 *   backups.php     §4     the newest backup and running state for the status answer
 *   policy.php      §7     safety settings pushed from the Cloud, only as far as the owner allows
 *   rate-limit.php  §6     the Cloud credential's write budget
 *
 * What the Cloud holds is an ordinary GoBuildWP access token, minted on this
 * site with the scope and ceiling the account owner chose. It passes every
 * check any other token does - safety profile, ceiling, scope, confirmations,
 * the owner's capability - so the site stays the final authority on what the
 * Cloud may do. Nothing here widens that.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Where a new pairing starts. Only §1 (begin) reads it: a pending pairing and the link it becomes
 * keep the `cloud_url` they began with, and the heartbeat, the unlink and the signed PageSpeed call
 * all use that stored value. A site paired before the move to gobuildwp.com therefore keeps calling
 * app.wppilot.co, which serves the same Cloud; the signature covers only the body, not the host.
 */
const WPPILOT_CLOUD_DEFAULT_URL = 'https://app.gobuildwp.com';

/** The pairing record, §3.4. Not autoloaded: nothing on a front-end request reads it. */
const WPPILOT_CLOUD_LINK_OPTION = 'wppilot_cloud_link';

/** The site's Ed25519 keypair, §3.2. Not autoloaded: it holds a secret key. */
const WPPILOT_CLOUD_KEYS_OPTION = 'wppilot_cloud_keys';

/** The plugin version the last heartbeat described, so an upgrade is reported once. */
const WPPILOT_CLOUD_SEEN_VERSION_OPTION = 'wppilot_cloud_seen_version';

const WPPILOT_CLOUD_HEARTBEAT_HOOK = 'wppilot_cloud_heartbeat';

/** Transient prefix for a pairing in flight, §1. */
const WPPILOT_CLOUD_PAIR_TRANSIENT_PREFIX = 'wppilot_cloud_pair_';

/** How long a pairing may take from Begin to Confirm, §1. */
const WPPILOT_CLOUD_PAIR_TTL = 600;

/** The write budget the Cloud credential is raised to, §6. */
const WPPILOT_CLOUD_RATE_LIMIT_FLOOR = 600;

/** Name of the access token the Cloud holds, §3.1. */
const WPPILOT_CLOUD_TOKEN_NAME = 'GoBuildWP Cloud';

require_once __DIR__ . '/pairing.php';
require_once __DIR__ . '/signing.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/heartbeat.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/updates.php';
require_once __DIR__ . '/backups.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/rate-limit.php';

add_action('admin_post_wppilot_cloud_begin', callback: 'wppilot_cloud_handle_begin');
add_action('admin_post_wppilot_cloud_return', callback: 'wppilot_cloud_handle_return');
add_action('admin_post_wppilot_cloud_confirm', callback: 'wppilot_cloud_handle_confirm');
add_action('admin_post_wppilot_cloud_cancel', callback: 'wppilot_cloud_handle_cancel');
add_action('admin_post_wppilot_cloud_disconnect', callback: 'wppilot_cloud_handle_disconnect');
add_action('admin_post_wppilot_cloud_manage', callback: 'wppilot_cloud_handle_manage');

add_action('rest_api_init', callback: 'wppilot_cloud_register_routes');

// No arguments: the event push (events.php) passes $event itself.
add_action(WPPILOT_CLOUD_HEARTBEAT_HOOK, callback: 'wppilot_cloud_send_heartbeat', priority: 10, accepted_args: 0);
add_action('admin_init', callback: 'wppilot_cloud_maintain_schedule');
add_action('upgrader_process_complete', callback: 'wppilot_cloud_on_upgrade', priority: 10, accepted_args: 2);
wppilot_cloud_register_event_hooks();

add_filter('wppilot_tool_call_rate_limit', callback: 'wppilot_cloud_rate_limit', priority: 10, accepted_args: 2);
