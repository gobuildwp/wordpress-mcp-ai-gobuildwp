<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteKitSharing;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\SessionLedger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Site Kit dashboard sharing, switched on the way Site Kit's own Dashboard sharing dialog does it.
 *
 * Verified against Site Kit by Google 1.189.0:
 *
 * - Storage is the `googlesitekit_dashboard_sharing` option (Module_Sharing_Settings): an object
 *   keyed by module slug, each `{sharedRoles: list<role>, management: "owner"|"all_admins"}`.
 *   Its sanitizer keeps only roles that have edit_posts, so `administrator` is always accepted.
 * - The dialog saves through POST google-site-kit/v1/core/modules/data/sharing-settings with
 *   `{data: {<slug>: {sharedRoles: [...]}}}` (REST_Dashboard_Sharing_Controller). The route needs
 *   googlesitekit_manage_options, which Site Kit grants only to an administrator who is signed in
 *   to Site Kit with Google, verified, on a site whose setup is complete. It then drops, per
 *   module, the sharedRoles of any module the user may not manage
 *   (googlesitekit_manage_module_sharing_options): only the module's owner, or any signed-in admin
 *   when the module's management is "all_admins" (PageSpeed Insights' default). The partial is
 *   merged one level deep, so sharedRoles is replaced whole and has to carry the roles already
 *   there.
 * - Saving changes the owner of a module that has no Google service entity (PageSpeed Insights):
 *   Modules' on_change listener writes the saving user's id into
 *   googlesitekit_pagespeed-insights_settings.ownerID. Search Console and Analytics keep theirs.
 *   So the undo restores that option too, after the sharing option, because restoring the sharing
 *   option fires the same listener.
 *
 * Going through the route rather than writing the option is the point: Site Kit decides who may
 * share whose Google data, and an administrator who is not the module's owner cannot widen it
 * through GoBuildWP any more than through Site Kit's screen. No Google token is read or written.
 */

const ABILITY = 'wppilot/site-kit-enable-sharing';

const STRATEGY = 'kits/site-kit-sharing';

const SHARING_OPTION = 'googlesitekit_dashboard_sharing';

/** The settings row whose ownerID Site Kit rewrites when PageSpeed Insights' sharing changes. */
const PSI_SETTINGS_OPTION = 'googlesitekit_pagespeed-insights_settings';

const MODULES = ['search-console', 'analytics-4', 'pagespeed-insights'];

const ROLE = 'administrator';

const SHARING_ROUTE = 'core/modules/data/sharing-settings';

function available(): bool
{
    return defined('GOOGLESITEKIT_VERSION');
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function enable_sharing(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard(ABILITY, $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    if (!available()) {
        return new WP_Error('kit_site_kit_not_active', 'Site Kit by Google is not active.', ['status' => 409]);
    }

    $requested = requested_modules($input);
    if ($requested instanceof WP_Error) {
        return $requested;
    }

    // Site Kit reports Search Console "connected" on a site nobody has signed in to Google on yet
    // (seen on 1.189.0), so the site-level answer comes first: until setup is complete there is
    // no owner whose Google account sharing could lend.
    $connection = site_kit_request('GET', 'core/site/data/connection', []);
    if (is_array($connection) && empty($connection['setupCompleted'])) {
        return new WP_Error(
            'kit_site_kit_not_connected',
            'Site Kit is not connected to Google yet, so there is nothing to share. A site administrator has to finish Site Kit setup (Site Kit > Dashboard > Sign in with Google) first; no plugin can sign in to Google for them.',
            ['status' => 409],
        );
    }

    $modules = module_list();
    if ($modules instanceof WP_Error) {
        return $modules;
    }

    $before = shared_roles();
    $report = [];
    $to_share = [];
    foreach ($requested as $slug) {
        $entry = $modules[$slug] ?? null;
        $roles = $before[$slug] ?? [];
        $owner = owner_of($entry);
        if (!is_array($entry) || empty($entry['active']) || empty($entry['connected'])) {
            $report[$slug] = ['status' => 'not_connected', 'shared_roles' => $roles, 'owner' => $owner];
            continue;
        }
        if (in_array(ROLE, $roles, strict: true)) {
            $report[$slug] = ['status' => 'already_shared', 'shared_roles' => $roles, 'owner' => $owner];
            continue;
        }
        if (!current_user_can('googlesitekit_manage_module_sharing_options', $slug)) {
            $report[$slug] = ['status' => 'not_permitted', 'shared_roles' => $roles, 'owner' => $owner];
            continue;
        }
        $to_share[$slug] = ['sharedRoles' => array_values(array_unique(array_merge($roles, [ROLE])))];
        $report[$slug] = ['status' => 'pending', 'shared_roles' => $roles, 'owner' => $owner];
    }

    if ($to_share === []) {
        return nothing_to_do($report);
    }

    $saved = site_kit_request('POST', SHARING_ROUTE, ['data' => $to_share]);
    if ($saved instanceof WP_Error) {
        $code = (string) $saved->get_error_code();
        if (in_array($code, ['rest_forbidden', 'rest_cannot_edit', 'rest_cannot_create'], strict: true)) {
            return new WP_Error(
                'kit_site_kit_sharing_not_permitted',
                'Site Kit refused the change: only an administrator who is signed in to Site Kit with Google may change dashboard sharing. Run this as the administrator who connected Site Kit, or share the modules in Site Kit > Dashboard sharing.',
                ['status' => 403, 'modules' => $report],
            );
        }
        return new WP_Error('kit_site_kit_sharing_failed', 'Site Kit could not save the sharing settings: ' . $saved->get_error_message(), ['status' => 502, 'modules' => $report]);
    }

    $after = shared_roles();
    $changed = false;
    foreach (array_keys($to_share) as $slug) {
        $roles = $after[$slug] ?? [];
        $shared = in_array(ROLE, $roles, strict: true);
        $changed = $changed || $shared;
        $report[$slug]['status'] = $shared ? 'shared' : 'refused_by_site_kit';
        $report[$slug]['shared_roles'] = $roles;
    }

    $new_owners = is_array($saved) && is_array($saved['newOwnerIDs'] ?? null) ? $saved['newOwnerIDs'] : [];

    return [
        'changed' => $changed,
        'role' => ROLE,
        'modules' => $report,
        'new_owner_ids' => (object) array_map('intval', $new_owners),
        'next' => 'Administrators who are not signed in to Site Kit now read these modules through the owner\'s Google account, read-only. Undo with wppilot/rollback-change and this call\'s change id; that also puts back the PageSpeed Insights owner Site Kit reassigns on save.',
    ];
}

/**
 * Nothing written: say why for each module, as a result when every module is already shared, as
 * an error naming who can do it otherwise.
 *
 * @param array<string, array<string, mixed>> $report
 * @return array<string, mixed>|WP_Error
 */
function nothing_to_do(array $report): array|WP_Error
{
    $statuses = array_column($report, 'status');
    if ($statuses !== [] && array_diff($statuses, ['already_shared']) === []) {
        return ['changed' => false, 'role' => ROLE, 'modules' => $report, 'new_owner_ids' => (object) [], 'next' => 'Already shared with Administrators; nothing changed.'];
    }
    if (in_array('not_permitted', $statuses, strict: true)) {
        $owners = [];
        foreach ($report as $slug => $row) {
            if ($row['status'] === 'not_permitted' && is_array($row['owner'])) {
                $owners[] = sprintf('%s: %s (user %d)', $slug, (string) $row['owner']['login'], (int) $row['owner']['id']);
            }
        }
        return new WP_Error(
            'kit_site_kit_sharing_not_permitted',
            'Site Kit lets only a module\'s owner (the administrator who connected it, signed in to Site Kit with Google) change its sharing'
                . ($owners !== [] ? ' — ' . implode('; ', $owners) : '')
                . '. Ask them to run this, or to share the modules in Site Kit > Dashboard sharing.',
            ['status' => 403, 'modules' => $report],
        );
    }

    return new WP_Error(
        'kit_site_kit_not_connected',
        'None of the requested Site Kit modules is connected to Google, so there is nothing to share. A site administrator has to finish Site Kit setup (Site Kit > Dashboard > Sign in with Google) first; no plugin can sign in to Google for them.',
        ['status' => 409, 'modules' => $report],
    );
}

/**
 * @param array<string, mixed> $input
 * @return list<string>|WP_Error
 */
function requested_modules(array $input): array|WP_Error
{
    if (!array_key_exists('modules', $input)) {
        return MODULES;
    }
    $modules = $input['modules'];
    if (!is_array($modules) || $modules === []) {
        return new WP_Error('kit_site_kit_invalid_input', 'modules must be a non-empty list of search-console, analytics-4 and pagespeed-insights.', ['status' => 400]);
    }
    $out = [];
    foreach ($modules as $slug) {
        if (!is_string($slug) || !in_array($slug, MODULES, strict: true)) {
            return new WP_Error('kit_site_kit_invalid_input', 'modules may only name search-console, analytics-4 and pagespeed-insights.', ['status' => 400]);
        }
        $out[] = $slug;
    }

    return array_values(array_unique($out));
}

/**
 * Site Kit's module list for the three modules, keyed by slug.
 *
 * @return array<string, array<string, mixed>>|WP_Error
 */
function module_list(): array|WP_Error
{
    $list = site_kit_request('GET', 'core/modules/data/list', []);
    if ($list instanceof WP_Error) {
        return new WP_Error(
            'kit_site_kit_unreadable',
            'Site Kit would not list its modules for this user: ' . $list->get_error_message(),
            ['status' => 403],
        );
    }
    $out = [];
    foreach (is_array($list) ? $list : [] as $module) {
        if (is_array($module) && in_array($module['slug'] ?? '', MODULES, strict: true)) {
            $out[(string) $module['slug']] = $module;
        }
    }

    return $out;
}

/**
 * @param mixed $entry
 * @return array{id: int, login: string}|null
 */
function owner_of(mixed $entry): ?array
{
    $owner = is_array($entry) && is_array($entry['owner'] ?? null) ? $entry['owner'] : null;
    if ($owner === null || (int) ($owner['id'] ?? 0) <= 0) {
        return null;
    }

    return ['id' => (int) $owner['id'], 'login' => (string) ($owner['login'] ?? '')];
}

/**
 * The roles each module is shared with, as Site Kit reads them (its option filters fill in the
 * default for PageSpeed Insights).
 *
 * @return array<string, list<string>>
 */
function shared_roles(): array
{
    /** @var mixed $settings */
    $settings = get_option(SHARING_OPTION, []);
    $out = [];
    foreach (MODULES as $slug) {
        $roles = is_array($settings) && is_array($settings[$slug]['sharedRoles'] ?? null) ? $settings[$slug]['sharedRoles'] : [];
        $out[$slug] = array_values(array_filter($roles, 'is_string'));
    }

    return $out;
}

/**
 * One request to a Site Kit route, in-process, so Site Kit's own permission checks and filters run.
 *
 * @param array<string, mixed> $params
 */
function site_kit_request(string $method, string $route, array $params): mixed
{
    /**
     * Short-circuit a Site Kit request: return anything but null to answer it without dispatching,
     * as pre_http_request does for HTTP. For hosts that reach Site Kit some other way, and tests.
     *
     * @param mixed                $answer null to dispatch.
     * @param string               $method GET or POST.
     * @param string               $route  Route under google-site-kit/v1/.
     * @param array<string, mixed> $params Query parameters (GET) or JSON body (POST).
     */
    /** @var mixed $answer */
    $answer = apply_filters('wppilot_kit_site_kit_sharing_pre_request', null, $method, $route, $params);
    if ($answer !== null) {
        return $answer;
    }
    if (!class_exists('WP_REST_Request') || !function_exists('rest_do_request')) {
        return new WP_Error('kit_site_kit_rest_unavailable', 'The WordPress REST API is not loaded.');
    }
    $request = new \WP_REST_Request($method, '/google-site-kit/v1/' . $route);
    if ($method === 'GET') {
        $request->set_query_params($params);
    } else {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($params));
    }
    $response = rest_do_request($request);
    if ($response instanceof WP_Error) {
        return $response;
    }
    if (is_object($response) && method_exists($response, 'is_error') && $response->is_error()) {
        return $response->as_error();
    }
    $data = is_object($response) && method_exists($response, 'get_data') ? $response->get_data() : $response;
    if ($data === null || is_scalar($data)) {
        return $data;
    }
    $json = wp_json_encode($data);

    return is_string($json) ? json_decode($json, true) : null;
}

// Undo.

/**
 * The two option rows as stored, read past Site Kit's option filters so "absent" stays absent.
 *
 * @return array<string, mixed>
 */
function snapshot(): array
{
    return [
        'type' => STRATEGY,
        'options' => [
            SHARING_OPTION => raw_option(SHARING_OPTION),
            PSI_SETTINGS_OPTION => raw_option(PSI_SETTINGS_OPTION),
        ],
    ];
}

/**
 * An option as stored, or ['absent' => true]. get_option() would run Site Kit's `option_` and
 * `default_option_` filters, which invent a PageSpeed Insights entry for a row that does not exist.
 *
 * @return array{absent: bool, value?: mixed}
 */
function raw_option(string $name): array
{
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return ['absent' => true];
    }
    $row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    if ($row === null) {
        return ['absent' => true];
    }

    return ['absent' => false, 'value' => maybe_unserialize((string) $row)];
}

/**
 * Put both rows back: the sharing option first, since saving it reassigns the PageSpeed Insights
 * owner, then that module's settings, which puts the owner back.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $options = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : null;
    if ($options === null || !isset($options[SHARING_OPTION], $options[PSI_SETTINGS_OPTION])) {
        return new WP_Error('kit_rollback_invalid', 'This change has no Site Kit sharing snapshot to restore.');
    }

    foreach ([SHARING_OPTION, PSI_SETTINGS_OPTION] as $name) {
        $row = $options[$name];
        if (!is_array($row) || !is_bool($row['absent'] ?? null) || (!$row['absent'] && !array_key_exists('value', $row))) {
            return new WP_Error('kit_rollback_invalid', sprintf('The snapshot of %s is damaged.', $name));
        }
    }
    // Validate every row before restoring either: damaged owner data must not partially undo sharing.
    foreach ([SHARING_OPTION, PSI_SETTINGS_OPTION] as $name) {
        $row = $options[$name];
        if (($row['absent'] ?? false) === true) {
            delete_option($name);
            continue;
        }
        // No autoload argument: the row keeps whatever Site Kit chose for it.
        update_option($name, $row['value'] ?? null);
    }

    $observed = snapshot();

    return [
        'restored' => [SHARING_OPTION, PSI_SETTINGS_OPTION],
        'verified' => fingerprint($observed['options']) === fingerprint($options),
    ];
}

/** @param array<array-key, mixed> $options */
function fingerprint(array $options): string
{
    return md5(serialize($options));
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
    if ($ledger instanceof SessionLedger) {
        $ledger->register_state(
            STRATEGY,
            static fn(array $snapshot): ?array => snapshot(),
            static fn(array $snapshot): string => 'site-kit-dashboard-sharing',
        );
    }
}
