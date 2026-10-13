<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Who is allowed to manage GoBuildWP.
 *
 * One capability answer for the admin screens, the REST surface, and the
 * ability permission callbacks, so they cannot drift apart.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runtime permission check for privileged GoBuildWP administration.
 */
function wppilot_current_user_can_manage(): bool
{
    return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

/**
 * Runtime permission check for a specific user.
 */
function wppilot_user_can_manage(int|WP_User $user): bool
{
    $user_id = $user instanceof WP_User ? $user->ID : $user;
    if ($user_id <= 0) {
        return false;
    }

    $manage_capability = 'manage_options';

    return is_multisite() ? is_super_admin($user_id) : user_can($user, $manage_capability);
}

/**
 * Capability string for WordPress APIs that cannot accept a boolean callback.
 */
function wppilot_manage_capability(): string
{
    return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Permission callback for privileged GoBuildWP administration.
 *
 * @return bool
 */
function wppilot_permission_callback()
{
    if (!wppilot_is_enabled()) {
        return false;
    }

    return wppilot_current_user_can_manage();
}

/**
 * Return a bearer token from a REST request header.
 */
function wppilot_rest_header_token(WP_REST_Request $request, string $header_name): string
{
    $header_token = $request->get_header($header_name);
    if (is_string($header_token) && trim($header_token) !== '') {
        return trim($header_token);
    }

    $authorization = $request->get_header('authorization');
    if (!is_string($authorization)) {
        return '';
    }

    $matches = [];
    if (preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}
