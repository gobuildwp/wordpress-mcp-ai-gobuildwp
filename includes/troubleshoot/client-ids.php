<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

namespace WPPilot\Troubleshoot\ClientIds;

use WP_Error;
use WPPilot\OAuth\Repositories\ClientRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * AI clients the troubleshooter can pre-register an OAuth client for. An entry earns its place
 * only when both facts are certain: the exact redirect URIs its OAuth flow uses, and a real
 * "client ID" field in its connection UI where the user can paste the value. A short correct
 * list beats a long broken one; add entries here as they are verified.
 *
 * @return array<string, array{label: string, client_name: string, redirect_uris: list<string>, field_hint: string}>
 */
function registry(): array
{
    return [
        'claude' => [
            'label' => __('Claude (claude.ai and Claude Desktop connectors)', domain: 'wppilot'),
            'client_name' => 'Claude',
            'redirect_uris' => [
                'https://claude.ai/api/mcp/auth_callback',
                'https://claude.com/api/mcp/auth_callback',
            ],
            'field_hint' => __(
                'In the Claude connector settings, open Advanced settings, paste this value into "OAuth Client ID" (leave the secret empty), save, then connect again.',
                domain: 'wppilot',
            ),
        ],
    ];
}

/**
 * Mint (or reuse) an admin-created OAuth client for the given registry entry and return the
 * client_id to paste into the AI client. Runs in wp-admin on behalf of an administrator, so the
 * anonymous-DCR rate limits and per-IP caps deliberately do not apply; the row is flagged
 * admin_created, which exempts it from the 24h pending prune (it lives until first use or manual
 * deletion from Connected Apps).
 *
 * @return array{client_id: string, label: string, field_hint: string}|WP_Error
 */
function generate(string $client_key): array|WP_Error
{
    $registry = registry();
    if (!array_key_exists($client_key, $registry)) {
        return new WP_Error('unknown_client', __('Unknown AI client.', domain: 'wppilot'), ['status' => 400]);
    }
    $entry = $registry[$client_key];
    $repository = new ClientRepository();
    $client_id = $repository->find_admin_client_id($entry['client_name']);
    if ($client_id === null) {
        $client_id = $repository->create(
            $entry['client_name'],
            $entry['redirect_uris'],
            $_SERVER['REMOTE_ADDR'] ?? '',
            admin_created: true,
        );
    }
    return ['client_id' => $client_id, 'label' => $entry['label'], 'field_hint' => $entry['field_hint']];
}
