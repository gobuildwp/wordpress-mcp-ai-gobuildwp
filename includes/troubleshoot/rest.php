<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Troubleshoot\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

function register_routes(): void
{
    $permission = static fn(): bool => is_user_logged_in() && \wppilot_current_user_can_manage();

    register_rest_route('wppilot/v1', route: '/troubleshoot/run-checks', args: [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => __NAMESPACE__ . '\\run_checks',
    ]);

    register_rest_route('wppilot/v1', route: '/troubleshoot/client-id', args: [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => __NAMESPACE__ . '\\client_id',
    ]);
}

function run_checks(WP_REST_Request $req): WP_REST_Response
{
    // @mago-expect analysis:mixed-assignment
    $raw = $req->get_json_params()['method'] ?? null;
    $method = in_array($raw, ['oauth', 'token', 'password'], strict: true) ? $raw : null;

    $checks = \WPPilot\Troubleshoot\Checks\run_all($method);
    // run_all() already probed for security/edge layers; current_security_edge_layers() caches that
    // result for this request, so the report reuses it without a second self-request.
    $layers = \WPPilot\Troubleshoot\Checks\current_security_edge_layers();
    $meta = [
        'site_url' => home_url('/'),
        'wppilot_version' => defined('WPPILOT_VERSION') ? WPPILOT_VERSION : '',
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'method' => $method ?? '',
    ];

    return new WP_REST_Response([
        'checks' => $checks,
        'layers' => $layers,
        'report' => \WPPilot\Troubleshoot\Checks\build_support_report($meta, $layers, $checks),
    ]);
}

function client_id(WP_REST_Request $req): WP_REST_Response|WP_Error
{
    // @mago-expect analysis:mixed-assignment
    $raw = $req->get_json_params()['client'] ?? null;
    $result = \WPPilot\Troubleshoot\ClientIds\generate(is_string($raw) ? $raw : '');
    if (is_wp_error($result)) {
        return $result;
    }
    return new WP_REST_Response($result, 201);
}
