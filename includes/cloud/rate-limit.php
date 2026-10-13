<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The Cloud credential's write budget, §6.
 *
 * The Cloud is one credential carrying every AI client its workspace connects
 * to this site, so the default of 120 writes a minute meant for one agent is
 * too tight for it. It is raised to at least 600, never lowered, and never
 * turned back on for a site that switched the limit off.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The credential id wppilot_rate_credential_id() assigns the linked token, or ''.
 *
 * A token identity is recorded with the client id `token-<id>`, and the rate
 * limiter keys OAuth-style identities by the digest of their client id.
 */
function wppilot_cloud_rate_credential(): string
{
    $link = wppilot_cloud_link();

    return $link === null ? '' : 'oauth:' . hash('sha256', 'token-' . $link['token_id']);
}

/**
 * Filter: wppilot_tool_call_rate_limit.
 *
 * @param mixed $limit      Calls per minute; 0 or less means no limit.
 * @param mixed $credential The credential being budgeted.
 * @return mixed
 */
function wppilot_cloud_rate_limit(mixed $limit, mixed $credential = ''): mixed
{
    if (!is_int($limit) || $limit <= 0 || !is_string($credential) || $credential === '') {
        return $limit;
    }

    $cloud = wppilot_cloud_rate_credential();
    if ($cloud === '' || !hash_equals($cloud, $credential)) {
        return $limit;
    }

    return max($limit, WPPILOT_CLOUD_RATE_LIMIT_FLOOR);
}
