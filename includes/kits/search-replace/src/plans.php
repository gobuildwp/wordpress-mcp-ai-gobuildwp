<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stored plans: what a preview found, kept server-side so apply writes exactly what the person
 * reviewed and nothing a caller rephrases on the way.
 *
 * Plans are non-autoloaded options rather than transients. A persistent object cache may evict a
 * transient at any time, and a background job that loses its plan halfway would have to fail a
 * batch the person already approved.
 */
const PLAN_OPTION_PREFIX = 'wppilot_kit_sr_plan_';
const PLAN_INDEX_OPTION = 'wppilot_kit_sr_plans';
const LOCK_OPTION_PREFIX = 'wppilot_kit_sr_lock_';

/** Seconds a plan stays usable after preview, or after the last apply that touched it. */
const PLAN_TTL = 3600;

/** A plan handed to a background job must outlive the job's queue and retries. */
const JOB_PLAN_TTL = 86_400;

/** Seconds a batch holds a plan; longer than any one batch runs. */
const LOCK_TTL = 120;

function new_plan_id(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * @param array<string, mixed> $plan
 */
function save_plan(array $plan): void
{
    $id = (string) $plan['id'];
    update_option(PLAN_OPTION_PREFIX . $id, $plan, false);
    $index = plan_index();
    if (($index[$id] ?? null) !== (int) $plan['expires_at']) {
        $index[$id] = (int) $plan['expires_at'];
        update_option(PLAN_INDEX_OPTION, $index, false);
    }
}

/**
 * A plan the current user made, that has not expired.
 *
 * A plan is bound to the person who previewed it: another administrator's agent must not apply a
 * diff it never showed its own user, even on the same site.
 *
 * @return array<string, mixed>|WP_Error
 */
function load_plan(string $id): array|WP_Error
{
    if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        return new WP_Error('kit_sr_plan_not_found', 'No plan has that id. Run search-replace-preview to make one.', ['status' => 404]);
    }
    // Read past the object cache: a batch in another request may have just saved this plan.
    wp_cache_delete(PLAN_OPTION_PREFIX . $id, 'options');
    /** @var mixed $plan */
    $plan = get_option(PLAN_OPTION_PREFIX . $id, null);
    if (!is_array($plan)) {
        return new WP_Error('kit_sr_plan_not_found', 'No plan has that id; it may have expired and been removed. Run search-replace-preview again.', ['status' => 404]);
    }
    if ((int) ($plan['owner'] ?? 0) <= 0 || (int) $plan['owner'] !== get_current_user_id()) {
        return new WP_Error('kit_sr_plan_not_yours', 'That plan was made by another user; preview again as yourself.', ['status' => 403]);
    }
    if ((int) ($plan['expires_at'] ?? 0) < time()) {
        forget_plan($id);
        return new WP_Error('kit_sr_plan_expired', 'That plan has expired. Run search-replace-preview again and show the person the new diff.', ['status' => 410]);
    }
    return $plan;
}

function forget_plan(string $id): void
{
    delete_option(PLAN_OPTION_PREFIX . $id);
    $index = plan_index();
    if (array_key_exists($id, $index)) {
        unset($index[$id]);
        update_option(PLAN_INDEX_OPTION, $index, false);
    }
}

/**
 * Remove expired plans. Run on every preview, so storage stays bounded by recent use.
 */
function sweep_plans(): void
{
    $now = time();
    foreach (plan_index() as $id => $expires_at) {
        if ($expires_at < $now) {
            forget_plan((string) $id);
        }
    }
}

/**
 * @return array<string, int>
 */
function plan_index(): array
{
    wp_cache_delete(PLAN_INDEX_OPTION, 'options');
    /** @var mixed $index */
    $index = get_option(PLAN_INDEX_OPTION, []);
    $clean = [];
    foreach (is_array($index) ? $index : [] as $id => $expires_at) {
        $clean[(string) $id] = (int) $expires_at;
    }
    return $clean;
}

/**
 * Take the plan for one batch. add_option() is an INSERT on a unique key, so of two requests
 * applying the same plan at once exactly one proceeds; the other would otherwise see the first
 * one's writes as drift and report posts it never touched as "changed since preview".
 */
function lock_plan(string $id): bool
{
    $key = LOCK_OPTION_PREFIX . $id;
    if (add_option($key, time() + LOCK_TTL, '', false)) {
        return true;
    }
    wp_cache_delete($key, 'options');
    if ((int) get_option($key, 0) > time()) {
        return false;
    }
    delete_option($key);
    return add_option($key, time() + LOCK_TTL, '', false);
}

function unlock_plan(string $id): void
{
    delete_option(LOCK_OPTION_PREFIX . $id);
}
