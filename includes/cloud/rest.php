<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The two routes the Cloud calls on this site, §4.
 *
 * Both accept exactly one credential: the access token this site gave the
 * Cloud in §3. Not another GoBuildWP token, not an administrator's cookie or
 * application password - a token for some other client must not be able to
 * read the Cloud's status or, worse, unlink it. The OAuth middleware lets a
 * token identity reach /wppilot/v1/cloud/* at all; the permission callback
 * here is what narrows that to the linked token.
 */

if (!defined('ABSPATH')) {
    exit();
}

function wppilot_cloud_register_routes(): void
{
    register_rest_route('wppilot/v1', '/cloud/status', [
        'methods' => 'GET',
        'callback' => 'wppilot_cloud_rest_status',
        'permission_callback' => 'wppilot_cloud_rest_permission',
    ]);

    register_rest_route('wppilot/v1', '/cloud/policy', [
        'methods' => 'POST',
        'callback' => 'wppilot_cloud_rest_policy',
        'permission_callback' => 'wppilot_cloud_rest_permission',
    ]);

    register_rest_route('wppilot/v1', '/cloud/unlink', [
        'methods' => 'POST',
        'callback' => 'wppilot_cloud_rest_unlink',
        'permission_callback' => 'wppilot_cloud_rest_permission',
    ]);
}

/**
 * Only the linked token may call these routes.
 *
 * 401 when nothing authenticated the request, 403 for any credential that is
 * not the linked token - including the right token after the link is gone.
 */
function wppilot_cloud_rest_permission(): bool|WP_Error
{
    $presented = function_exists('wppilot_current_token_id') ? wppilot_current_token_id() : 0;

    if (wppilot_cloud_token_is_linked($presented, wppilot_cloud_link())) {
        return true;
    }

    if (get_current_user_id() <= 0) {
        return new WP_Error('rest_not_logged_in', __('GoBuildWP Cloud authentication required.', domain: 'wppilot'), [
            'status' => 401,
        ]);
    }

    return new WP_Error('wppilot_cloud_forbidden', __('Only the access token paired with GoBuildWP Cloud may use this route.', domain: 'wppilot'), [
        'status' => 403,
    ]);
}

/**
 * The safety profile the Cloud's calls run under: the site's, lowered by the
 * ceiling the Cloud's token was minted with.
 *
 * Asked outside a token request too (the heartbeat runs on cron), which is why
 * this reads the linked token's ceiling rather than the request's.
 */
function wppilot_cloud_safety_profile(): string
{
    $site = wppilot_get_safety_profile();
    $link = wppilot_cloud_link();
    $policy = $link !== null && function_exists('wppilot_token_policy') ? wppilot_token_policy($link['token_id']) : null;
    $ceiling = $policy['ceiling'] ?? '';

    return $ceiling !== '' && wppilot_safety_profile_rank($ceiling) < wppilot_safety_profile_rank($site)
        ? $ceiling
        : $site;
}

/**
 * GET /wppilot/v1/cloud/status.
 */
function wppilot_cloud_rest_status(): WP_REST_Response|WP_Error
{
    $link = wppilot_cloud_link();
    if ($link === null) {
        return new WP_Error('wppilot_cloud_forbidden', __('This site is not connected to GoBuildWP Cloud.', domain: 'wppilot'), [
            'status' => 403,
        ]);
    }

    $versions = wppilot_cloud_versions();

    $status = [
        'site_id' => $link['site_id'],
        'plugin_version' => $versions['plugin'],
        'wp_version' => $versions['wp'],
        'php_version' => $versions['php'],
        'pro_version' => $versions['pro'],
        'safety_profile' => wppilot_cloud_safety_profile(),
        'abilities_enabled' => (bool) wppilot_is_enabled(),
        'home_url' => home_url(),
        'updates' => wppilot_cloud_pending_updates(),
        'backup' => wppilot_cloud_backup_summary(),
        'policy' => wppilot_cloud_policy_status(),
    ];
    // The site-issues kit's summary: counts, newest fatal, newest few issues. Left out (not null)
    // when the kit is not loaded, which is what older plugins send too.
    if (function_exists('WPPilot\Kits\SiteIssues\cloud_summary')) {
        $status['issues'] = \WPPilot\Kits\SiteIssues\cloud_summary();
    }

    return new WP_REST_Response($status, 200);
}

/**
 * POST /wppilot/v1/cloud/unlink: the Cloud disconnected the site from its side.
 *
 * No call back to the Cloud - it started this.
 */
function wppilot_cloud_rest_unlink(): WP_REST_Response
{
    $link = wppilot_cloud_link();
    if ($link !== null) {
        wppilot_cloud_revoke_token($link['token_id']);
        wppilot_cloud_clear_link();
    }

    return new WP_REST_Response(['ok' => true], 200);
}
