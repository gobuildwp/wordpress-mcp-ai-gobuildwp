<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Standalone checks for the connection-safety features (discovery cache purge, OAuth table repair,
 * IP allowlist, drafts-only access, bounded session approvals, compact/strict tool lists, request
 * log, connection survival kit). Runs with plain PHP against the unit-test doubles, for machines without the PHPUnit dev
 * dependencies:
 *
 *     php tests/connection-safety-smoke.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/doubles/mcp-surface.php';

if (!function_exists('has_action')) {
    function has_action(string $hook, mixed $callback = false): bool|int
    {
        return ($GLOBALS['wp_filter'][$hook] ?? []) !== [];
    }
}

function smoke_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------------------------
// 1. Discovery cache purge.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/oauth/discovery-cache.php';

use WPPilot\OAuth\DiscoveryCache;

WPPilot_Test_State::reset();
WPPilot_Test_State::$options = [];

$urls = DiscoveryCache\discovery_urls();
smoke_check(in_array('https://example.test/.well-known/oauth-protected-resource', $urls, true), 'protected-resource URL missing');
smoke_check(in_array('https://example.test/.well-known/oauth-authorization-server', $urls, true), 'authorization-server URL missing');
smoke_check(in_array('https://example.test/.well-known/openid-configuration', $urls, true), 'openid-configuration URL missing');
smoke_check(count($urls) === count(array_unique($urls)), 'discovery URLs are not de-duplicated');

$none = DiscoveryCache\purge('test');
smoke_check($none['detected'] === 0 && $none['purged'] === [], 'purge reported a cache on a site without one');

// LiteSpeed: purged by URL, never purge-all.
$litespeed_urls = [];
$purged_all = false;
add_filter('litespeed_purge_url', static function (string $url) use (&$litespeed_urls): void {
    $litespeed_urls[] = $url;
});
add_filter('litespeed_purge_all', static function () use (&$purged_all): void {
    $purged_all = true;
});
// SiteGround: must always be handed a URL (no argument flushes the whole site).
// Declared inside a block so it only exists from here on, not hoisted to the top of the file.
if (!function_exists('sg_cachepress_purge_cache')) {
    function sg_cachepress_purge_cache(?string $url = null): void
    {
        $GLOBALS['smoke_sg_calls'][] = $url;
    }
}
// Breeze is detected but has no per-URL purge.
define('BREEZE_VERSION', '2.0.0');

$GLOBALS['smoke_sg_calls'] = [];
$result = DiscoveryCache\purge('test');
smoke_check($litespeed_urls === $urls, 'LiteSpeed was not purged URL by URL');
smoke_check(!$purged_all, 'LiteSpeed purge-all was called');
smoke_check($GLOBALS['smoke_sg_calls'] === $urls, 'SiteGround purge was not called with each URL');
smoke_check(!in_array(null, $GLOBALS['smoke_sg_calls'], true), 'SiteGround purge was called without a URL');
smoke_check(in_array('LiteSpeed Cache', $result['purged'], true) && in_array('SiteGround Speed Optimizer', $result['purged'], true), 'purged list incomplete');
smoke_check(array_column($result['unsupported'], 'label') === ['Breeze'], 'Breeze was not reported as unsupported');
smoke_check(DiscoveryCache\last_result() !== null, 'purge result was not stored');

// A filter can add a purger; a throwing one is reported, not fatal.
add_filter('wppilot_discovery_cache_purgers', static function (array $providers): array {
    $providers[] = ['id' => 'boom', 'label' => 'Boom', 'detected' => true, 'purge' => static function (): void {
        throw new RuntimeException('nope');
    }, 'manual' => ''];

    return $providers;
});
$result = DiscoveryCache\purge('test');
smoke_check($result['errors'] === [['label' => 'Boom', 'error' => 'nope']], 'a throwing purger was not reported');

// Pending flag: set by a settings change, consumed once on wp_loaded.
DiscoveryCache\mark_pending();
smoke_check(get_option(DiscoveryCache\PENDING_OPTION) === '1', 'pending flag not set');
DiscoveryCache\run_pending();
smoke_check(get_option(DiscoveryCache\PENDING_OPTION, '0') === '0', 'pending flag not cleared');

// ---------------------------------------------------------------------------------------------
// 1-2. Doctor findings for the discovery cache and the OAuth tables.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/troubleshoot/checks.php';
require_once dirname(__DIR__) . '/includes/troubleshoot/doctor.php';

use WPPilot\Troubleshoot\Doctor;

smoke_check(Doctor\check_oauth_tables([])['status'] === 'pass', 'installed OAuth tables did not pass');
$tables = Doctor\check_oauth_tables(['wp_wppilot_oauth_clients']);
smoke_check($tables['status'] === 'fail' && str_contains($tables['fix'], 'Repair OAuth tables'), 'missing OAuth tables did not point at the repair');
smoke_check(Doctor\check_discovery_cache([], $urls, null)['status'] === 'pass', 'no cache plugin should pass');
smoke_check(Doctor\check_discovery_cache([['label' => 'LiteSpeed Cache', 'supported' => true, 'manual' => '']], $urls, null)['status'] === 'pass', 'a purgeable cache should pass');
$manual = Doctor\check_discovery_cache([['label' => 'Breeze', 'supported' => false, 'manual' => 'Purge by hand.']], $urls, null);
smoke_check($manual['status'] === 'warn' && str_contains($manual['fix'], 'Breeze: Purge by hand.'), 'an unpurgeable cache should warn with its manual step');

// ---------------------------------------------------------------------------------------------
// 3. MCP IP allowlist.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/access/ip-allowlist.php';

WPPilot_Test_State::reset();
WPPilot_Test_State::$options = [];

smoke_check(wppilot_ip_matches('192.0.2.10', '192.0.2.0/24'), 'IPv4 CIDR did not match');
smoke_check(!wppilot_ip_matches('192.0.3.10', '192.0.2.0/24'), 'IPv4 CIDR matched outside its range');
smoke_check(wppilot_ip_matches('10.1.2.3', '10.0.0.0/12') && !wppilot_ip_matches('10.16.0.1', '10.0.0.0/12'), 'non-octet IPv4 prefix wrong');
smoke_check(wppilot_ip_matches('203.0.113.7', '203.0.113.7'), 'single IPv4 did not match');
smoke_check(wppilot_ip_matches('2001:db8::1', '2001:db8::/32') && !wppilot_ip_matches('2001:db9::1', '2001:db8::/32'), 'IPv6 CIDR wrong');
smoke_check(wppilot_ip_matches('::ffff:192.0.2.10', '192.0.2.0/24'), 'IPv4-mapped IPv6 did not match its IPv4 range');
smoke_check(wppilot_ip_matches('192.0.2.10', '::ffff:192.0.2.0/120'), 'IPv4 did not match a mapped IPv6 range');
smoke_check(!wppilot_ip_matches('192.0.2.10', '2001:db8::/32'), 'IPv4 matched an IPv6 range');
smoke_check(wppilot_ip_matches('8.8.8.8', '0.0.0.0/0'), '/0 did not match everything');
foreach (['999.1.1.1', '10.0.0.0/33', '2001:db8::/129', 'example.com', '10.0.0.0/abc', ''] as $bad) {
    smoke_check(wppilot_ip_parse($bad) === null, "invalid entry accepted: {$bad}");
}

$validated = wppilot_ip_allowlist_validate(['203.0.113.7 # office', '', '# comment only', '2001:db8::/32', '203.0.113.7']);
smoke_check($validated === ['entries' => ['203.0.113.7', '2001:db8::/32'], 'invalid' => []], 'validation did not strip comments and duplicates');
smoke_check(wppilot_ip_allowlist_validate(['10.0.0.1', 'nope'])['invalid'] === ['nope'], 'invalid entry not reported');

// Client address: X-Forwarded-For is ignored unless REMOTE_ADDR is a trusted proxy.
$spoofed = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];
smoke_check(wppilot_ip_client_address($spoofed) === '198.51.100.9', 'X-Forwarded-For believed from an untrusted peer');
$trust = static fn (): array => ['198.51.100.0/24'];
add_filter('wppilot_trusted_proxies', $trust);
smoke_check(wppilot_ip_client_address($spoofed) === '203.0.113.7', 'X-Forwarded-For ignored from a trusted proxy');
// The client can prepend anything; the rightmost non-proxy hop wins.
$chain = ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.7, 198.51.100.20'];
smoke_check(wppilot_ip_client_address($chain) === '203.0.113.7', 'forwarded chain not read right to left');
smoke_check(wppilot_ip_client_address(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => 'garbage']) === '198.51.100.9', 'garbage forwarded header not ignored');
$GLOBALS['wp_filter']['wppilot_trusted_proxies'] = [];

// Empty list allows everyone; a list refuses others on MCP routes only.
$mcp = new WPPilot_Test_Rest_Request('/mcp/wppilot');
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
smoke_check(wppilot_ip_allowlist_rest_guard(null, null, $mcp) === null, 'empty allowlist refused a request');
wppilot_ip_allowlist_save(['203.0.113.0/24']);
$refused = wppilot_ip_allowlist_rest_guard(null, null, $mcp);
smoke_check($refused instanceof WP_Error && $refused->get_error_code() === 'wppilot_ip_not_allowed', 'unlisted address reached MCP');
smoke_check(wppilot_ip_allowlist_rest_guard(null, null, new WPPilot_Test_Rest_Request('/wppilot/v1/oauth/token')) instanceof WP_Error, 'OAuth token endpoint not guarded');
smoke_check(wppilot_ip_allowlist_rest_guard(null, null, new WPPilot_Test_Rest_Request('/gobuildwp/v1/device/token')) instanceof WP_Error, 'device token endpoint not guarded');
smoke_check(wppilot_ip_allowlist_rest_guard(null, null, new WPPilot_Test_Rest_Request('/wp/v2/posts')) === null, 'non-agent route was guarded');
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
smoke_check(wppilot_ip_allowlist_rest_guard(null, null, $mcp) === null, 'listed address refused');
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
define('WPPILOT_MCP_IP_ALLOWLIST_DISABLED', true);
smoke_check(wppilot_ip_allowlist_rest_guard(null, null, $mcp) === null, 'wp-config kill switch did not disable enforcement');
unset($_SERVER['REMOTE_ADDR']);

// ---------------------------------------------------------------------------------------------
// 4a. Drafts-only access tokens.
// ---------------------------------------------------------------------------------------------
// The real token lookup runs (the bootstrap loads agent-identities.php and the OAuth middleware):
// the request identity is recorded the way the middleware records a bearer token, and the token's
// policy is seeded in the request cache in place of the tokens table.
function smoke_use_token(?string $ceiling): void
{
    \WPPilot\OAuth\Middleware\reset_request_context();
    if ($ceiling === null) {
        return;
    }
    \WPPilot\OAuth\Middleware\record_oauth_identity(1, ['mcp'], 'token-7', 'token');
    wppilot_token_policy_cache(7, ['id' => 7, 'name' => 't', 'scope' => null, 'ceiling' => $ceiling]);
}
if (!function_exists('get_post_status')) {
    function get_post_status(mixed $post = null): string|false
    {
        return $GLOBALS['smoke_posts'][(int) $post]['status'] ?? false;
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type(mixed $post = null): string|false
    {
        return $GLOBALS['smoke_posts'][(int) $post]['type'] ?? false;
    }
}
require_once dirname(__DIR__) . '/includes/access/drafts-only.php';

$GLOBALS['smoke_posts'] = [
    10 => ['status' => 'draft', 'type' => 'post'],
    11 => ['status' => 'publish', 'type' => 'page'],
    12 => ['status' => 'draft', 'type' => 'wp_template'],
];
$write_option = new WP_Ability('wppilot/update-option');
$read = new WP_Ability('wppilot/get-post', ['annotations' => ['readonly' => true]]);
$create = new WP_Ability('wppilot/create-post');

smoke_use_token('production');
smoke_check(!wppilot_drafts_only_active(), 'a production token read as drafts-only');
smoke_check(wppilot_drafts_only_scope_error($write_option) === null, 'drafts-only check ran for a production token');
smoke_check(wppilot_drafts_only_input_error('wppilot/create-post', ['status' => 'publish']) === null, 'publish refused for a production token');

smoke_use_token('drafts');
smoke_check(wppilot_drafts_only_active(), 'drafts token not detected');
smoke_check(wppilot_drafts_only_scope_error($write_option) instanceof WP_Error, 'drafts token may write options');
smoke_check(wppilot_drafts_only_scope_error($read) === null, 'drafts token may not read');
smoke_check(wppilot_drafts_only_scope_error($create) === null, 'drafts token may not create posts');
smoke_check(wppilot_drafts_only_scope_error(new WP_Ability('mcp-adapter/discover-abilities')) === null, 'drafts token lost discovery');
smoke_check(wppilot_drafts_only_input_error('wppilot/create-post', ['title' => 'x']) === null, 'default-status create refused');
smoke_check(wppilot_drafts_only_input_error('wppilot/create-post', ['status' => 'pending']) === null, 'pending create refused');
foreach (['publish', 'future', 'private'] as $status) {
    $error = wppilot_drafts_only_input_error('wppilot/create-post', ['status' => $status]);
    smoke_check($error instanceof WP_Error && $error->get_error_code() === 'wppilot_drafts_only', "create as {$status} allowed");
}
smoke_check(wppilot_drafts_only_input_error('wppilot/create-post', ['post_type' => 'wp_navigation']) instanceof WP_Error, 'drafts token may create wp_* posts');
smoke_check(wppilot_drafts_only_input_error('wppilot/update-post', ['post_id' => 10, 'title' => 'x']) === null, 'draft update refused');
smoke_check(wppilot_drafts_only_input_error('wppilot/update-post', ['id' => 11, 'title' => 'x']) instanceof WP_Error, 'published post update allowed');
smoke_check(wppilot_drafts_only_input_error('wppilot/update-post', ['post_id' => 10, 'status' => 'publish']) instanceof WP_Error, 'draft published by update');
smoke_check(wppilot_drafts_only_input_error('wppilot/gutenberg-write-content', ['target_id' => 11]) instanceof WP_Error, 'live post block write allowed');
smoke_check(wppilot_drafts_only_input_error('wppilot/gutenberg-write-content', ['target_id' => 12]) instanceof WP_Error, 'template block write allowed');
smoke_check(wppilot_drafts_only_input_error('wppilot/set-featured-image', ['post_id' => 11, 'attachment_id' => 5]) instanceof WP_Error, 'live post featured image allowed');
smoke_check(wppilot_drafts_only_input_error('wppilot/assign-terms', ['post_id' => 10, 'taxonomy' => 'category', 'term_ids' => [1]]) === null, 'draft term assignment refused');

// Backstops.
smoke_check(wppilot_drafts_only_filter_post_data(['post_status' => 'publish'])['post_status'] === 'draft', 'backstop did not force draft');
smoke_check(wppilot_drafts_only_filter_post_data(['post_status' => 'inherit'])['post_status'] === 'inherit', 'backstop changed a revision');
smoke_check(wppilot_drafts_only_filter_empty_content(false, ['ID' => 11, 'post_type' => 'page']) === true, 'backstop let a live post update through');
smoke_check(wppilot_drafts_only_filter_empty_content(false, ['ID' => 10, 'post_type' => 'post']) === false, 'backstop refused a draft update');
smoke_check(wppilot_drafts_only_filter_empty_content(false, ['ID' => 0, 'post_type' => 'post']) === false, 'backstop refused a new post');
smoke_use_token(null);
smoke_check(wppilot_drafts_only_filter_post_data(['post_status' => 'publish'])['post_status'] === 'publish', 'backstop ran without a drafts token');

// ---------------------------------------------------------------------------------------------
// 4b. New-app approval.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/oauth/app-approval.php';

use WPPilot\OAuth\AppApproval;

WPPilot_Test_State::$options = [];
smoke_check(AppApproval\is_approved('abc'), 'approval off still held a client');
AppApproval\set_enabled(true);
smoke_check(AppApproval\enabled() && !AppApproval\is_approved('abc'), 'approval on let an unknown client through');
AppApproval\note_pending('abc', 'Evil App');
smoke_check(array_keys(AppApproval\pending_clients()) === ['abc'], 'pending client not recorded');
$approved_events = [];
add_filter('wppilot_oauth_client_approved', static function (string $id) use (&$approved_events): void {
    $approved_events[] = $id;
});
AppApproval\approve('abc');
smoke_check(AppApproval\is_approved('abc') && AppApproval\pending_clients() === [], 'approval did not take');
smoke_check($approved_events === ['abc'], 'approval was not announced');
for ($i = 0; $i < AppApproval\MAX_PENDING + 10; $i++) {
    AppApproval\note_pending('flood-' . $i, 'x');
}
smoke_check(count(AppApproval\pending_clients()) === AppApproval\MAX_PENDING, 'pending list is unbounded');
AppApproval\reject('flood-59');
smoke_check(!isset(AppApproval\pending_clients()['flood-59']) && !AppApproval\is_approved('flood-59'), 'reject approved or kept the client');
AppApproval\set_enabled(false);
smoke_check(AppApproval\is_approved('anything'), 'approval off still held a client');

// 5. Session approvals, the always-ask list and the approval log.
require_once __DIR__ . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/includes/approvals/log.php';
require_once dirname(__DIR__) . '/includes/approvals/session.php';
$smoke_previous_wpdb = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}
if (!function_exists('wppilot_current_agent')) {
    function wppilot_current_agent(?array $set = null): array
    {
        static $agent = ['method' => 'direct', 'credential' => '', 'label' => '', 'client' => '', 'client_version' => ''];
        if ($set !== null) {
            $agent = $set;
        }

        return $agent;
    }
}
$smoke_agent = static function (string $credential): void {
    wppilot_current_agent(['method' => 'token', 'credential' => $credential, 'label' => 'Smoke agent', 'client' => 'smoke', 'client_version' => '1']);
};
$smoke_events = static function (string $event): int {
    return count(wppilot_approval_log_query(['event' => $event], 500));
};
$smoke_pending = static function (string $ability, array $input): array {
    $request = wppilot_confirmation_find_request($ability, wppilot_confirmation_input_hash($input), get_current_user_id(), 'pending');
    smoke_check(is_array($request), 'no pending request for ' . $ability);

    return (array) $request;
};
WPPilot_Test_State::$current_user_id = 1;
update_option(WPPILOT_CONFIRMATION_MODE_OPTION, 'human');
update_option(WPPILOT_CONFIRMATION_REQUESTS_OPTION, []);
$smoke_agent('token-7');
$smoke_destructive = new WP_Ability('wppilot/smoke-delete-comment', ['annotations' => ['destructive' => true]]);
smoke_check(wppilot_ability_risk($smoke_destructive) === 'destructive', 'session smoke ability is not destructive');

smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 5], 'mcp') instanceof WP_Error, 'human mode ran a destructive call without asking');
$smoke_request = $smoke_pending('wppilot/smoke-delete-comment', ['id' => 5]);
smoke_check(($smoke_request['credential'] ?? '') === 'token-7', 'request did not record the agent credential');
smoke_check(wppilot_confirmation_session_allowed($smoke_request), 'session approval not offered for a destructive ability');
smoke_check(wppilot_confirmation_grant_session((string) $smoke_request['id'], 2), 'session approval was not granted');
smoke_check(!wppilot_confirmation_grant_session((string) $smoke_request['id'], 2), 'a decided request granted a second session');
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 5], 'mcp') === 'approval-url', 'the asking call did not run on its approval');
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 6], 'mcp') === 'session', 'a later call was not covered by the session');
smoke_check(wppilot_confirmation_last_approver() === 2, 'session call not credited to the person who granted it');
wppilot_confirmation_note('wppilot/smoke-delete-comment', 'session');
$smoke_note = wppilot_confirmation_note('wppilot/smoke-delete-comment', clear: true);
smoke_check(($smoke_note['approved_by'] ?? 0) === 2, 'ledger note lost the session approver');
smoke_check($smoke_events('session-granted') === 1 && $smoke_events('session-used') === 1, 'session grant or use not logged');
smoke_check($smoke_events('approved') === 0, 'a session grant was also logged as a one-off approval');

// Never widened: another connection, no connection, another user, another ability.
$smoke_agent('token-8');
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 7], 'mcp') instanceof WP_Error, 'session covered another connection');
$smoke_agent('');
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 8], 'mcp') instanceof WP_Error, 'session covered a call with no credential');
smoke_check(!wppilot_confirmation_session_allowed($smoke_pending('wppilot/smoke-delete-comment', ['id' => 8])), 'session offered to a call with no credential');
$smoke_agent('token-7');
WPPilot_Test_State::$current_user_id = 3;
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 7], 'mcp') instanceof WP_Error, 'session covered another user');
WPPilot_Test_State::$current_user_id = 1;
$smoke_other = new WP_Ability('wppilot/smoke-delete-tag', ['annotations' => ['destructive' => true]]);
smoke_check(wppilot_confirm_ability_call($smoke_other, ['id' => 7], 'mcp') instanceof WP_Error, 'session covered another ability');

// Critical abilities are never offered a session; a denial is logged.
$smoke_critical = new WP_Ability('wppilot/smoke-run', [], [], 'code-execution');
smoke_check(wppilot_confirm_ability_call($smoke_critical, ['code' => 'x'], 'mcp') instanceof WP_Error, 'critical call ran without asking');
$critical_request = $smoke_pending('wppilot/smoke-run', ['code' => 'x']);
smoke_check(!wppilot_confirmation_session_allowed($critical_request), 'session offered for a critical ability');
smoke_check(!wppilot_confirmation_grant_session((string) $critical_request['id'], 2), 'session granted for a critical ability');
smoke_check(wppilot_confirmation_decide((string) $critical_request['id'], false, 2), 'deny failed');
smoke_check($smoke_events('denied') === 1, 'denial not logged');

// A denial of one exact call outranks a session covering its ability.
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 11], 'mcp') === 'session', 'second session call not covered');
update_option(WPPILOT_CONFIRMATION_REQUESTS_OPTION, array_merge(wppilot_confirmation_requests(), ['d' => [
    'id' => 'd', 'ability' => 'wppilot/smoke-delete-comment', 'input_sha256' => wppilot_confirmation_input_hash(['id' => 12]),
    'user_id' => 1, 'status' => 'denied', 'created_at' => time(), 'expires_at' => time() + 300,
]]));
$denied = wppilot_confirm_ability_call($smoke_destructive, ['id' => 12], 'mcp');
smoke_check($denied instanceof WP_Error && $denied->get_error_code() === 'wppilot_confirmation_denied', 'session overrode a denial');

// The call budget runs out.
add_filter('wppilot_confirmation_session_calls', static fn(): int => 2);
wppilot_confirm_ability_call($smoke_other, ['id' => 1], 'mcp');
smoke_check(wppilot_confirmation_grant_session((string) $smoke_pending('wppilot/smoke-delete-tag', ['id' => 1])['id'], 2), 'budget session not granted');
remove_all_filters('wppilot_confirmation_session_calls');
smoke_check(wppilot_confirm_ability_call($smoke_other, ['id' => 2], 'mcp') === 'session', 'budget call 1 not covered');
smoke_check(wppilot_confirm_ability_call($smoke_other, ['id' => 3], 'mcp') === 'session', 'budget call 2 not covered');
smoke_check(wppilot_confirm_ability_call($smoke_other, ['id' => 4], 'mcp') instanceof WP_Error, 'session ran past its call budget');
// And the clock.
smoke_check(count(wppilot_confirmation_sessions()) === 1, 'expected only the comment session to be live');
smoke_check(count(wppilot_confirmation_sessions(time() + 31 * MINUTE_IN_SECONDS)) === 0, 'session outlived its 30 minutes');

// Always ask: adding an ability ends its session, requires confirmation and blocks new sessions.
$smoke_plain = new WP_Ability('wppilot/smoke-update-title');
smoke_check(!wppilot_ability_requires_confirmation($smoke_plain), 'plain write already needs confirmation');
$saved = wppilot_confirmation_set_always_ask(["wppilot/smoke-update-title\n", 'wppilot/smoke-delete-comment', 'not an ability', 'WPPilot/Smoke-Update-Title'], 2);
smoke_check($saved === ['wppilot/smoke-update-title', 'wppilot/smoke-delete-comment'], 'always-ask list not normalised');
smoke_check(wppilot_ability_requires_confirmation($smoke_plain), 'always-ask did not require confirmation');
smoke_check(count(wppilot_confirmation_sessions()) === 0, 'always-ask left a live session for the ability');
smoke_check($smoke_events('session-revoked') === 1 && $smoke_events('always-ask-changed') === 1, 'always-ask change or revoke not logged');
smoke_check(wppilot_confirm_ability_call($smoke_destructive, ['id' => 10], 'mcp') instanceof WP_Error, 'always-ask ability still session-covered');
smoke_check(!wppilot_confirmation_session_allowed($smoke_pending('wppilot/smoke-delete-comment', ['id' => 10])), 'always-ask ability offered a session');
wppilot_confirmation_set_always_ask(['wppilot/smoke-update-title', 'wppilot/smoke-delete-comment'], 2);
smoke_check($smoke_events('always-ask-changed') === 1, 'an unchanged always-ask save was logged');

// The log: filters, no input stored, CSV defused, retention is the only delete.
smoke_check(wppilot_approval_log_record('session-used', ['ability' => '=HYPERLINK("x")', 'agent' => "+cmd\n", 'input_sha256' => 'not-a-hash']), 'log insert failed');
smoke_check(!wppilot_approval_log_record('made-up-event'), 'log accepted an unknown event');
$rows = wppilot_approval_log_query(['ability' => '=HYPERLINK("x")']);
smoke_check(count($rows) === 1 && $rows[0]['input_sha256'] === '' && $rows[0]['agent'] === '+cmd', 'log row not sanitised');
$csv = wppilot_approval_log_csv($rows);
smoke_check(str_contains($csv, "\"'=HYPERLINK(\"\"x\"\")\"") && str_contains($csv, "\"'+cmd\""), 'CSV cell not defused');
$filters = wppilot_approval_log_read_filters(['event' => 'nope', 'since' => '2026-13', 'until' => '2026-11-13']);
smoke_check($filters['event'] === '' && $filters['since'] === '' && $filters['until'] === '2026-11-13', 'filters not read');
$total = count(wppilot_approval_log_query([], 500));
smoke_check($total > 5, 'log has fewer rows than the checks above wrote');
smoke_check(wppilot_approval_log_prune() === 0 && count(wppilot_approval_log_query([], 500)) === $total, 'prune removed fresh rows');
smoke_check(wppilot_approval_log_prune(time() + 91 * DAY_IN_SECONDS) === $total, 'prune kept rows past retention');
add_filter('wppilot_approval_log_retention_days', static fn(): int => 1);
smoke_check(wppilot_approval_log_retention_days() === 30, 'retention went below its 30 day floor');
remove_all_filters('wppilot_approval_log_retention_days');

update_option(WPPILOT_CONFIRMATION_MODE_OPTION, 'argument');
update_option(WPPILOT_CONFIRMATION_ALWAYS_ASK_OPTION, []);
update_option(WPPILOT_CONFIRMATION_REQUESTS_OPTION, []);
WPPilot_Test_State::$current_user_id = 0;
$GLOBALS['wpdb'] = $smoke_previous_wpdb;

// ---------------------------------------------------------------------------------------------
// 6. Compact tool list and strict schemas.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/mcp/tool-modes.php';

final class Smoke_Runnable_Ability extends WP_Ability
{
    /** @var list<mixed> */
    public array $ran = [];

    public function execute(mixed $input = null): mixed
    {
        $this->ran[] = $input;

        return ['ok' => true];
    }
}

$smoke_tool_abilities = [
    'mcp-adapter/discover-abilities' => new Smoke_Runnable_Ability('mcp-adapter/discover-abilities', ['annotations' => ['readonly' => true]], ['type' => 'object', 'properties' => []]),
    'mcp-adapter/get-ability-info' => new Smoke_Runnable_Ability('mcp-adapter/get-ability-info', ['annotations' => ['readonly' => true]], [
        'type' => 'object', 'properties' => ['ability_name' => ['type' => 'string']], 'required' => ['ability_name'],
    ]),
    'mcp-adapter/execute-ability' => new Smoke_Runnable_Ability('mcp-adapter/execute-ability', [], [
        'type' => 'object',
        'properties' => ['ability_name' => ['type' => 'string'], 'parameters' => ['type' => 'object']],
        'required' => ['ability_name', 'parameters'],
    ]),
    'wppilot/smoke-list-posts' => new Smoke_Runnable_Ability('wppilot/smoke-list-posts', ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]], [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['publish', 'draft']],
            'per_page' => ['type' => 'integer'],
            'filter' => ['type' => 'object', 'properties' => ['author' => ['type' => 'integer'], 'tag' => ['type' => 'string']], 'required' => ['author']],
            'meta' => ['type' => 'object'],
            'cursor' => ['type' => ['string', 'null']],
            'pick' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
        ],
        'required' => ['per_page'],
    ]),
    'wppilot/smoke-wipe' => new Smoke_Runnable_Ability('wppilot/smoke-wipe', ['mcp' => ['public' => true], 'annotations' => ['destructive' => true]], [
        'type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'],
    ]),
    'wppilot/smoke-hidden' => new Smoke_Runnable_Ability('wppilot/smoke-hidden', ['annotations' => ['readonly' => true]]),
];
$GLOBALS['smoke_tool_abilities'] = $smoke_tool_abilities;
if (!function_exists('wp_get_abilities')) {
    function wp_get_abilities(array $args = []): array
    {
        return $GLOBALS['smoke_tool_abilities'] ?? [];
    }
}
if (!function_exists('wp_get_ability')) {
    function wp_get_ability(string $name): ?WP_Ability
    {
        return $GLOBALS['smoke_tool_abilities'][$name] ?? null;
    }
}
$smoke_tool_names = static fn(): array => array_map(static fn(array $tool): string => (string) $tool['name'], WPPilot\Mcp\list_tools());
$smoke_tool = static function (string $name): array {
    foreach (WPPilot\Mcp\list_tools() as $tool) {
        if ($tool['name'] === $name) {
            return $tool;
        }
    }
    smoke_check(false, 'tool not listed: ' . $name);

    return [];
};
$smoke_call = static fn(string $tool, array $arguments): array => WPPilot\Mcp\call_tool(['name' => $tool, 'arguments' => $arguments], 1)['body']['result'] ?? [];
WPPilot_Test_State::$current_user_id = 1;
update_option(WPPILOT_CONFIRMATION_MODE_OPTION, 'argument');

// Off: one tool per exposed ability, schemas as authored.
$full = $smoke_tool_names();
smoke_check(in_array('wppilot_smoke_list_posts', $full, true) && !in_array('wppilot_smoke_hidden', $full, true), 'full tool list wrong');
smoke_check(!in_array('mcp_adapter_execute_ability', $full, true), 'full list advertised the unexposed meta-tool');
smoke_check(!isset($smoke_tool('wppilot_smoke_list_posts')['inputSchema']['additionalProperties']), 'schema made strict while the switch is off');

// Compact: the three meta-tools only.
update_option(WPPilot\Mcp\COMPACT_TOOLS_OPTION, '1');
$compact = $smoke_tool_names();
sort($compact);
smoke_check($compact === ['mcp_adapter_discover_abilities', 'mcp_adapter_execute_ability', 'mcp_adapter_get_ability_info'], 'compact list is not the three meta-tools: ' . implode(',', $compact));

// execute-ability on the modern transport is gated as the ability it names.
$read_target = $smoke_tool_abilities['wppilot/smoke-list-posts'];
$result = $smoke_call('mcp_adapter_execute_ability', ['ability_name' => 'wppilot/smoke-list-posts', 'parameters' => ['per_page' => 3]]);
smoke_check(($result['isError'] ?? false) !== true && $read_target->ran === [['per_page' => 3]], 'execute-ability did not run the named ability with its parameters');
smoke_check($smoke_tool_abilities['mcp-adapter/execute-ability']->ran === [], 'execute-ability ran inside the adapter');
$wipe = $smoke_tool_abilities['wppilot/smoke-wipe'];
$result = $smoke_call('mcp_adapter_execute_ability', ['ability_name' => 'wppilot/smoke-wipe', 'parameters' => ['id' => 4]]);
smoke_check(($result['isError'] ?? false) === true && $wipe->ran === [], 'execute-ability skipped the confirmation of the named ability');
$smoke_call('mcp_adapter_execute_ability', ['ability_name' => 'wppilot/smoke-wipe', 'parameters' => ['id' => 4, 'confirm' => true]]);
smoke_check($wipe->ran === [['id' => 4]], 'confirmed execute-ability call did not run, or kept confirm');
foreach (['wppilot/smoke-hidden', 'mcp-adapter/execute-ability', 'wppilot/nope', ''] as $smoke_target) {
    $out = WPPilot\Mcp\call_tool(['name' => 'mcp_adapter_execute_ability', 'arguments' => ['ability_name' => $smoke_target, 'parameters' => []]], 1);
    smoke_check(isset($out['body']['error']), 'execute-ability reached an unlisted target: ' . $smoke_target);
}
smoke_check($smoke_tool_abilities['wppilot/smoke-hidden']->ran === [], 'hidden ability ran through execute-ability');
update_option(WPPilot\Mcp\COMPACT_TOOLS_OPTION, '0');

// Strict: closed objects, every property required, optional ones nullable.
update_option(WPPilot\Mcp\STRICT_SCHEMAS_OPTION, '1');
$schema = $smoke_tool('wppilot_smoke_list_posts')['inputSchema'];
smoke_check($schema['additionalProperties'] === false, 'strict root not closed');
smoke_check($schema['required'] === ['search', 'status', 'per_page', 'filter', 'meta', 'cursor', 'pick'], 'strict root does not require every property');
smoke_check($schema['properties']['per_page']['type'] === 'integer', 'a required property was made nullable');
smoke_check($schema['properties']['search']['type'] === ['string', 'null'], 'optional string not nullable');
smoke_check($schema['properties']['status']['enum'] === ['publish', 'draft', null], 'optional enum does not admit null');
smoke_check($schema['properties']['filter']['type'] === ['object', 'null'] && $schema['properties']['filter']['additionalProperties'] === false, 'nested object not closed or not nullable');
smoke_check($schema['properties']['filter']['required'] === ['author', 'tag'] && $schema['properties']['filter']['properties']['tag']['type'] === ['string', 'null'], 'nested optional not nullable');
smoke_check(!isset($schema['properties']['meta']['additionalProperties']), 'free-form object was closed');
smoke_check($schema['properties']['cursor']['type'] === ['string', 'null'], 'already-nullable type changed');
smoke_check(count($schema['properties']['pick']['anyOf']) === 3, 'anyOf property not made nullable');
$no_input = WPPilot\Mcp\strict_schema(WPPilot\Mcp\force_schema_objects(['type' => 'object', 'properties' => []]));
smoke_check($no_input['additionalProperties'] === false && $no_input['required'] === [], 'no-input schema not closed');
// The legacy list gets the same treatment.
$legacy = WPPilot\Mcp\repair_tool_list([['name' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]]);
smoke_check(($legacy[0]['inputSchema']['additionalProperties'] ?? null) === false, 'legacy tool list not made strict');

// The nulls a strict client sends for optional fields are dropped before the ability runs.
$read_target->ran = [];
$smoke_call('wppilot_smoke_list_posts', ['search' => null, 'status' => null, 'per_page' => 5, 'filter' => ['author' => 2, 'tag' => null], 'meta' => null, 'cursor' => null, 'pick' => null]);
smoke_check($read_target->ran === [['per_page' => 5, 'filter' => ['author' => 2], 'cursor' => null]], 'strict nulls not dropped as expected: ' . json_encode($read_target->ran));
update_option(WPPilot\Mcp\STRICT_SCHEMAS_OPTION, '0');
$read_target->ran = [];
$smoke_call('wppilot_smoke_list_posts', ['per_page' => 5, 'search' => null]);
smoke_check($read_target->ran === [['per_page' => 5, 'search' => null]], 'nulls dropped while strict mode is off');

// Settings save.
WPPilot\Mcp\save_tool_mode_settings([WPPilot\Mcp\COMPACT_TOOLS_OPTION => '1']);
smoke_check(WPPilot\Mcp\compact_tools_enabled() && !WPPilot\Mcp\strict_schemas_enabled(), 'tool mode settings not saved');
WPPilot\Mcp\save_tool_mode_settings([]);
smoke_check(!WPPilot\Mcp\compact_tools_enabled(), 'compact mode not switched off');
unset($GLOBALS['smoke_tool_abilities']);
WPPilot_Test_State::$current_user_id = 0;

// ---------------------------------------------------------------------------------------------
// 7. MCP request log.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/mcp/request-log.php';
$smoke_previous_wpdb = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
if (!function_exists('wppilot_connection_client_from_handshake')) {
    // connections.php is not loaded here (wppilot_current_agent() is stubbed above); the log only
    // needs the clientInfo name of an initialize body.
    function wppilot_connection_client_from_handshake(mixed $params): ?array
    {
        $name = is_array($params) && ($params['method'] ?? null) === 'initialize' ? ($params['params']['clientInfo']['name'] ?? null) : null;

        return is_string($name) ? ['name' => $name, 'version' => ''] : null;
    }
}

$smoke_log = static function (string $route, mixed $json, mixed $data, int $status = 200): void {
    $request = new WPPilot_Test_Rest_Request($route, 'POST', $json);
    wppilot_mcp_request_log_start(null, null, $request);
    wppilot_mcp_request_log_finish(new WPPilot_Test_Rest_Response($data, $status), null, $request);
};
$smoke_log_rows = static fn(array $filters = []): array => wppilot_mcp_request_log_query($filters, 500);
$smoke_call_body = static fn(string $tool, array $arguments): array => [
    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
];
WPPilot_Test_State::$current_user_id = 3;
$smoke_agent('token-7');

// A read is logged with who, what, how it ended and how long it took; its input never is.
$smoke_log('/mcp/wppilot', $smoke_call_body('wppilot_list_posts', ['search' => 'SMOKE-SECRET-ARG']), ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'SMOKE-SECRET-RESULT']]]]);
$rows = $smoke_log_rows();
smoke_check(count($rows) === 1, 'tools/call not logged');
$row = $rows[0];
smoke_check($row['rpc_method'] === 'tools/call' && $row['tool'] === 'wppilot_list_posts' && $row['outcome'] === 'ok', 'logged call wrong: ' . json_encode($row));
smoke_check($row['credential'] === 'token-7' && $row['credential_label'] === 'Smoke agent' && $row['client'] === 'smoke' && $row['auth_method'] === 'token' && $row['user_id'] === '3', 'logged identity wrong: ' . json_encode($row));
smoke_check(is_numeric($row['duration_ms']) && $row['http_status'] === '200', 'duration or status missing');

// execute-ability names the ability it ran, and still nothing of its parameters.
$smoke_log('/mcp/mcp-adapter-default-server', $smoke_call_body('mcp-adapter-execute-ability', ['ability_name' => 'wppilot/delete-post', 'parameters' => ['id' => 'SMOKE-SECRET-ARG']]), ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => "Needs confirm.\nRetry."]]]]);
$row = $smoke_log_rows()[0];
smoke_check($row['tool'] === 'mcp-adapter-execute-ability > wppilot/delete-post', 'execute-ability target not logged: ' . $row['tool']);
smoke_check($row['outcome'] === 'tool-error' && $row['error'] === 'Needs confirm. Retry.', 'tool error not logged: ' . json_encode($row));
$smoke_log('/mcp/wppilot', $smoke_call_body('mcp_adapter_execute_ability', ['ability_name' => 'not a name; DROP', 'parameters' => []]), ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'Unknown ability: x']]);
$row = $smoke_log_rows()[0];
smoke_check($row['tool'] === 'mcp_adapter_execute_ability' && $row['outcome'] === 'error' && $row['error'] === 'Unknown ability: x', 'JSON-RPC error or odd target logged wrong: ' . json_encode($row));

// A refusal with no parsed body still counts; notifications, client responses and other routes do not.
$smoke_agent('');
$smoke_log('/mcp/wppilot-oauth', null, ['code' => 'rest_forbidden', 'message' => 'Address not allowed.', 'data' => ['status' => 403]], 403);
$row = $smoke_log_rows()[0];
smoke_check($row['outcome'] === 'denied' && $row['error'] === 'Address not allowed.' && $row['http_status'] === '403' && $row['rpc_method'] === '', 'refusal not logged: ' . json_encode($row));
$before = count($smoke_log_rows());
$smoke_log('/mcp/wppilot', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], null, 202);
$smoke_log('/mcp/wppilot', ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['action' => 'accept']], null, 202);
$smoke_log('/wppilot/v1/abilities/x/run', $smoke_call_body('x', []), ['ok' => true]);
$smoke_log('/mcp/wppilot', null, null, 200);
smoke_check(count($smoke_log_rows()) === $before, 'a non-request was logged');

// A batch is one row; resources/read keeps only the resource, not its query string.
$smoke_log('/mcp/wppilot', [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']], [['jsonrpc' => '2.0', 'id' => 1, 'result' => []], ['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => 1, 'message' => 'no']]]);
$row = $smoke_log_rows()[0];
smoke_check($row['rpc_method'] === 'batch' && $row['tool'] === '2 requests' && $row['outcome'] === 'error', 'batch logged wrong: ' . json_encode($row));
$smoke_log('/mcp/wppilot', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'wppilot://skills/a?token=SMOKE-SECRET-ARG']], ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
smoke_check($smoke_log_rows()[0]['tool'] === 'wppilot://skills/a', 'resource query string logged');

// The handshake names the client before any session remembers it.
wppilot_current_agent(['method' => 'direct', 'credential' => '', 'label' => '', 'client' => '', 'client_version' => '']);
$smoke_log('/mcp/wppilot', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['clientInfo' => ['name' => '=HYPERLINK("x")']]], ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
smoke_check($smoke_log_rows()[0]['client'] === '=HYPERLINK("x")', 'handshake client not logged');

$smoke_dump = json_encode($smoke_log_rows());
smoke_check(!str_contains((string) $smoke_dump, 'SMOKE-SECRET'), 'arguments or results reached the request log');

// Filters and CSV.
smoke_check(count($smoke_log_rows(['outcome' => 'tool-error'])) === 1, 'outcome filter wrong');
smoke_check(count($smoke_log_rows(['tool' => 'delete-post'])) === 1 && count($smoke_log_rows(['client' => 'smo'])) === 6, 'substring filters wrong');
smoke_check(count($smoke_log_rows(['rpc_method' => 'batch'])) === 1, 'method filter wrong');
$filters = wppilot_mcp_request_log_read_filters(['outcome' => 'nope', 'since' => '2026-13', 'until' => '2026-11-13', 'tool' => 'x']);
smoke_check($filters['outcome'] === '' && $filters['since'] === '' && $filters['until'] === '2026-11-13' && $filters['tool'] === 'x', 'request log filters not normalised');
$csv = wppilot_mcp_request_log_csv($smoke_log_rows(['client' => 'HYPERLINK']));
smoke_check(str_contains($csv, "\"'=HYPERLINK(\"\"x\"\")\""), 'CSV cell not defused');
smoke_check(str_starts_with($csv, "\xEF\xBB\xBF\"id\",\"created_at\",\"duration_ms\""), 'CSV header wrong');

// Switched off by filter.
$before = count($smoke_log_rows());
add_filter('wppilot_mcp_request_log_enabled', static fn(): bool => false);
$smoke_log('/mcp/wppilot', $smoke_call_body('wppilot_list_posts', []), ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
remove_all_filters('wppilot_mcp_request_log_enabled');
smoke_check(count($smoke_log_rows()) === $before, 'request logged while switched off');

// Bounded: never more than 500 rows, the oldest go first; nothing past 30 days.
$smoke_agent('token-7');
for ($i = 0; $i < WPPILOT_MCP_REQUEST_LOG_MAX_ROWS + 10; $i++) {
    $smoke_log('/mcp/wppilot', $smoke_call_body('wppilot_bulk_' . $i, []), ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
}
$rows = $smoke_log_rows();
$smoke_total = (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . wppilot_mcp_request_log_table());
smoke_check($smoke_total === WPPILOT_MCP_REQUEST_LOG_MAX_ROWS, 'request log not capped: ' . $smoke_total);
smoke_check($rows[0]['tool'] === 'wppilot_bulk_509' && end($rows)['tool'] === 'wppilot_bulk_10', 'cap did not drop the oldest rows');
smoke_check(wppilot_mcp_request_log_prune() === 0, 'prune removed fresh rows');
smoke_check(wppilot_mcp_request_log_prune(time() + 31 * DAY_IN_SECONDS) === WPPILOT_MCP_REQUEST_LOG_MAX_ROWS && $smoke_log_rows() === [], 'rows past 30 days kept');
$GLOBALS['wpdb'] = $smoke_previous_wpdb;
WPPilot_Test_State::$current_user_id = 0;

// ---------------------------------------------------------------------------------------------
// 8. Connection survival kit: alias header, ?rest_route= fallback, Allow GoBuildWP Cloud.
// ---------------------------------------------------------------------------------------------
if (!function_exists('rest_get_url_prefix')) {
    function rest_get_url_prefix(): string
    {
        return 'wp-json';
    }
}
if (!function_exists('add_query_arg')) {
    /** @param array<string, string> $args */
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}
require_once dirname(__DIR__) . '/includes/access/connection-survival.php';

WPPilot_Test_State::reset();
WPPilot_Test_State::$options = [];

smoke_check(wppilot_request_rest_route_early(['REQUEST_URI' => '/wp-json/mcp/wppilot'], []) === '/mcp/wppilot', 'pretty route not read');
smoke_check(wppilot_request_rest_route_early(['REQUEST_URI' => '/'], ['rest_route' => '/mcp/wppilot/']) === '/mcp/wppilot', 'rest_route query not read');
smoke_check(wppilot_request_rest_route_early(['REQUEST_URI' => '/blog/wp-json-ish'], []) === '', 'non-REST path read as a route');

$alias = ['REQUEST_URI' => '/wp-json/mcp/wppilot', 'HTTP_X_GOBUILDWP_AUTHORIZATION' => 'Bearer smoke-token'];
smoke_check(wppilot_auth_alias_header($alias, [], true) === 'Bearer smoke-token', 'alias header not used on an MCP route over HTTPS');
smoke_check(wppilot_auth_alias_header($alias, [], false) === '', 'alias header used over plain HTTP');
smoke_check(wppilot_auth_alias_header($alias + ['HTTP_AUTHORIZATION' => 'Bearer real'], [], true) === '', 'alias header used while Authorization arrived');
smoke_check(wppilot_auth_alias_header($alias + ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer real'], [], true) === '', 'alias header used while the redirected Authorization arrived');
smoke_check(wppilot_auth_alias_header(['HTTP_X_GOBUILDWP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'] + $alias, [], true) === '', 'alias header accepted a Basic credential');
smoke_check(wppilot_auth_alias_header(['REQUEST_URI' => '/wp-json/wp/v2/users'] + $alias, [], true) === '', 'alias header used off the agent routes');
smoke_check(wppilot_auth_alias_header(['REQUEST_URI' => '/wp-json/wppilot/v1/oauth/token'] + $alias, [], true) === 'Bearer smoke-token', 'alias header refused on an OAuth route');
smoke_check(wppilot_auth_alias_header(['REQUEST_URI' => '/'] + $alias, ['rest_route' => '/wppilot/v1/abilities/wppilot/list-posts/run'], true) === 'Bearer smoke-token', 'alias header refused on the ability run route');

smoke_check(!wppilot_rest_fallback_active() && wppilot_mcp_endpoint_url() === 'https://example.test/wp-json/mcp/wppilot', 'fallback active before any probe');
smoke_check(wppilot_rest_fallback_decide(404, 500)['status'] === 'unknown' && !wppilot_rest_fallback_active(), 'fallback chosen when it does not answer either');
smoke_check(wppilot_rest_fallback_decide(403, 200)['use_fallback'] && wppilot_rest_fallback_active(), 'fallback not chosen for a blocked /wp-json/');
smoke_check(str_contains(wppilot_mcp_endpoint_url(), 'rest_route=') && str_starts_with(wppilot_mcp_endpoint_url(), 'https://example.test/'), 'Connect URL did not switch to the fallback');
smoke_check(wppilot_rest_fallback_decide(200, 0)['status'] === 'ok' && !wppilot_rest_fallback_active(), 'fallback kept after /wp-json/ came back');

smoke_check(wppilot_cloud_egress_parse('{"ips":["203.0.113.4","2001:db8::/48","203.0.113.4"]}') === ['203.0.113.4', '2001:db8::/48'], 'egress list not parsed');
smoke_check(wppilot_cloud_egress_parse('{"ips":["203.0.113.4","example.com"]}') instanceof WP_Error, 'egress list with a hostname accepted');
smoke_check(wppilot_cloud_egress_parse('{"ips":[]}') instanceof WP_Error && wppilot_cloud_egress_parse('nope') instanceof WP_Error, 'empty or broken egress list accepted');
smoke_check(wppilot_cloud_egress_parse((string) json_encode(['ips' => array_fill(0, WPPILOT_CLOUD_EGRESS_MAX + 1, '192.0.2.1')])) instanceof WP_Error, 'oversized egress list accepted');

// An empty allowlist admits everyone: the Cloud's addresses must not be added and lock others out.
$plan = wppilot_cloud_allowlist_plan(['203.0.113.4']);
smoke_check(!$plan['needed'] && $plan['add'] === [], 'Cloud addresses planned into an empty allowlist');
smoke_check(wppilot_cloud_allowlist_apply(['203.0.113.4'])['status'] === 'info' && wppilot_ip_allowlist() === [], 'empty allowlist was written');
wppilot_ip_allowlist_save(['192.0.2.0/24', '203.0.113.4']);
$plan = wppilot_cloud_allowlist_plan(['203.0.113.4', '198.51.100.9']);
smoke_check($plan['needed'] && $plan['add'] === ['198.51.100.9'] && count($plan['entries']) === 3, 'allowlist plan wrong: ' . json_encode($plan));
$applied = wppilot_cloud_allowlist_apply(['203.0.113.4', '198.51.100.9']);
smoke_check($applied['status'] === 'success' && wppilot_ip_allowlist() === ['192.0.2.0/24', '203.0.113.4', '198.51.100.9'], 'Cloud addresses not added');
smoke_check(wppilot_cloud_allowlist_apply(['198.51.100.9'])['status'] === 'info', 'an already-allowed list was written again');

// ---------------------------------------------------------------------------------------------
// 9. In-chat approval card: only a click in the card, or wp-admin, approves; never the agent.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/mcp/apps.php';
require_once dirname(__DIR__) . '/includes/mcp/approval-card.php';
require_once dirname(__DIR__) . '/includes/mcp/extensions.php';

$smoke_card_previous_wpdb = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
WPPilot_Test_State::$current_user_id = 1;
update_option(WPPILOT_CONFIRMATION_MODE_OPTION, 'human');
update_option(WPPILOT_CONFIRMATION_REQUESTS_OPTION, []);
$smoke_agent('token-card');
$smoke_card_ability = new WP_Ability('wppilot/smoke-delete-page', ['annotations' => ['destructive' => true]]);
$smoke_apps_params = ['_meta' => [WPPilot\Mcp\META_CLIENT_CAPABILITIES => ['extensions' => [WPPilot\Mcp\Apps\EXTENSION_ID => ['mimeTypes' => [WPPilot\Mcp\Apps\MIME_TYPE]]]]]];
$smoke_card_refusal = static function (array $input) use ($smoke_card_ability, $smoke_apps_params): array {
    $error = wppilot_confirm_ability_call($smoke_card_ability, $input, 'mcp');
    smoke_check($error instanceof WP_Error && $error->get_error_code() === 'wppilot_human_confirmation_required', 'card smoke call was not sent for approval');

    return WPPilot\Mcp\decorate_refusal(WPPilot\Mcp\tool_error($error, 1), $error, $smoke_apps_params)['body']['result'];
};
$smoke_decide = static function (string $request_id, string $decision, string $token): array {
    return WPPilot\Mcp\call_tool(['name' => WPPilot\Mcp\ApprovalCard\DECIDE_TOOL, 'arguments' => ['request_id' => $request_id, 'decision' => $decision, 'card_token' => $token]], 1)['body']['result'] ?? [];
};

// The card is served, and its decide tool is listed app-only.
smoke_check(WPPilot\Mcp\Apps\read_contents(WPPilot\Mcp\ApprovalCard\CARD_URI) !== null, 'approval card resource not served');
$smoke_decide_tools = array_values(array_filter(WPPilot\Mcp\with_extension_tools([]), static fn(array $t): bool => $t['name'] === WPPilot\Mcp\ApprovalCard\DECIDE_TOOL));
smoke_check(count($smoke_decide_tools) === 1 && $smoke_decide_tools[0]['_meta']['ui']['visibility'] === ['app'], 'decide tool not listed app-only');

// A client without Apps gets the wp-admin link exactly as before.
$smoke_plain_error = wppilot_confirm_ability_call($smoke_card_ability, ['id' => 1], 'mcp');
$smoke_plain = WPPilot\Mcp\decorate_refusal(WPPilot\Mcp\tool_error($smoke_plain_error, 1), $smoke_plain_error, [])['body']['result'];
smoke_check(!isset($smoke_plain['_meta']['ui']) && !isset($smoke_plain['_meta'][WPPilot\Mcp\ApprovalCard\META_KEY]) && !isset($smoke_plain['structuredContent']['approval']), 'card linked for a client without Apps');

// With Apps: the card is linked, and its token is only in _meta, never where the model reads.
$smoke_card = $smoke_card_refusal(['id' => 1]);
$smoke_token = (string) ($smoke_card['_meta'][WPPilot\Mcp\ApprovalCard\META_KEY]['token'] ?? '');
$smoke_request_id = (string) ($smoke_card['structuredContent']['approval']['request_id'] ?? '');
smoke_check($smoke_token !== '' && $smoke_request_id !== '', 'card token or request id missing');
smoke_check(($smoke_card['_meta']['ui']['resourceUri'] ?? '') === WPPilot\Mcp\ApprovalCard\CARD_URI, 'card not linked from the refusal');
smoke_check(!str_contains((string) json_encode($smoke_card['content']), $smoke_token) && !str_contains((string) json_encode($smoke_card['structuredContent']), $smoke_token), 'card token leaked into what the model reads');
smoke_check(str_contains((string) $smoke_card['content'][0]['text'], 'wp-admin'), 'wp-admin link dropped from the text');

// Forged, borrowed or cross-purpose tokens decide nothing.
$smoke_refused = static fn(array $result): bool => ($result['isError'] ?? false) === true && ($result['structuredContent']['error'] ?? '') === 'wppilot_approval_card_refused';
smoke_check($smoke_refused($smoke_decide($smoke_request_id, 'approve', 'c1.e30.AAAA')), 'forged card token approved');
smoke_check($smoke_refused($smoke_decide($smoke_request_id, 'approve', $smoke_token . 'x')), 'tampered card token approved');
$smoke_elicit = wppilot_confirmation_issue_token('wppilot/smoke-delete-page', wppilot_confirmation_input_hash(['id' => 1]), 1);
smoke_check($smoke_refused($smoke_decide($smoke_request_id, 'approve', $smoke_elicit)), 'an elicitation token approved on the card');
$smoke_card_2 = $smoke_card_refusal(['id' => 2]);
$smoke_request_2 = (string) $smoke_card_2['structuredContent']['approval']['request_id'];
smoke_check($smoke_refused($smoke_decide($smoke_request_2, 'approve', $smoke_token)), 'a token for another request approved');
WPPilot_Test_State::$current_user_id = 3;
smoke_check($smoke_refused($smoke_decide($smoke_request_id, 'approve', $smoke_token)), 'another user approved with the card token');
WPPilot_Test_State::$current_user_id = 1;
$smoke_agent('token-other');
smoke_check($smoke_refused($smoke_decide($smoke_request_id, 'approve', $smoke_token)), 'another connection approved with the card token');
$smoke_agent('token-card');
smoke_check(wppilot_confirmation_get_request($smoke_request_id)['status'] === 'pending', 'a refused card call changed the request');
smoke_check(wppilot_confirm_ability_call($smoke_card_ability, ['id' => 1], 'mcp') instanceof WP_Error, 'call ran before anyone approved');

// The real click approves that one call once, logged, with no session.
$smoke_sessions_before = count(wppilot_confirmation_sessions());
$smoke_ok = $smoke_decide($smoke_request_id, 'approve', $smoke_token);
smoke_check(($smoke_ok['isError'] ?? true) === false && ($smoke_ok['structuredContent']['decided'] ?? false) === true, 'card approval failed: ' . json_encode($smoke_ok));
smoke_check($smoke_events('card-approved') === 1 && $smoke_events('approved') === 0, 'card approval not logged as such');
smoke_check(count(wppilot_confirmation_sessions()) === $smoke_sessions_before, 'card approval granted a session');
smoke_check($smoke_refused($smoke_decide($smoke_request_id, 'approve', $smoke_token)), 'card token reused');
smoke_check(wppilot_confirm_ability_call($smoke_card_ability, ['id' => 1], 'mcp') === 'approval-url', 'approved call did not run');
smoke_check(wppilot_confirm_ability_call($smoke_card_ability, ['id' => 1], 'mcp') instanceof WP_Error, 'one card approval ran the call twice');
smoke_check(wppilot_confirm_ability_call($smoke_card_ability, ['id' => 3], 'mcp') instanceof WP_Error, 'card approval covered a different input');

// Deny is one click and blocks the retry.
$smoke_deny_token = (string) $smoke_card_2['_meta'][WPPilot\Mcp\ApprovalCard\META_KEY]['token'];
$smoke_denied_card = $smoke_decide($smoke_request_2, 'deny', $smoke_deny_token);
smoke_check(($smoke_denied_card['structuredContent']['decided'] ?? false) === true, 'card deny failed');
$smoke_denied_retry = wppilot_confirm_ability_call($smoke_card_ability, ['id' => 2], 'mcp');
smoke_check($smoke_denied_retry instanceof WP_Error && $smoke_denied_retry->get_error_code() === 'wppilot_confirmation_denied', 'card denial not honoured');

// Fresh installs start with a person approving; existing installs keep what they had.
delete_option(WPPILOT_CONFIRMATION_MODE_OPTION);
delete_option('wppilot_ai_abilities_enabled');
wppilot_confirmation_default_on_activate();
smoke_check(get_option(WPPILOT_CONFIRMATION_MODE_OPTION) === 'human', 'fresh install did not default to a person approving');
delete_option(WPPILOT_CONFIRMATION_MODE_OPTION);
update_option('wppilot_ai_abilities_enabled', '1');
wppilot_confirmation_default_on_activate();
smoke_check(wppilot_confirmation_mode() === 'argument' && get_option(WPPILOT_CONFIRMATION_MODE_OPTION, null) === null, 'existing install had its confirmation mode changed');
delete_option('wppilot_ai_abilities_enabled');
$GLOBALS['wpdb'] = $smoke_card_previous_wpdb;

// ---------------------------------------------------------------------------------------------
// 10. Connection levels: per OAuth app / application password, mcp:read, narrowing only.
// ---------------------------------------------------------------------------------------------
// connections.php is not loaded here (it would redeclare the wppilot_current_agent() stub), so its
// credential lookup is reproduced: the same key shapes the Connections table records.
if (!function_exists('wppilot_connection_credential')) {
    function wppilot_connection_credential(string $method, int $user_id): ?array
    {
        if ($method === 'oauth') {
            $identity = \WPPilot\OAuth\Middleware\request_oauth_identity();
            $client_id = is_array($identity) ? (string) ($identity['client_id'] ?? '') : '';

            return ['key' => $client_id !== '' ? 'client-' . hash('sha256', $client_id) : 'user-' . $user_id, 'label' => 'OAuth client'];
        }
        $uuid = $GLOBALS['wp_rest_application_password_uuid'] ?? null;

        return $method === 'password' && is_string($uuid) && $uuid !== '' ? ['key' => $uuid, 'label' => 'Laptop'] : null;
    }
}
require_once dirname(__DIR__) . '/includes/access/connection-levels.php';
require_once dirname(__DIR__) . '/includes/oauth/bootstrap.php'; // normalize_authorization_scope(); boot() only runs on plugins_loaded.

$smoke_level_user = WPPilot_Test_State::$current_user_id;
WPPilot_Test_State::$current_user_id = 3;
delete_option(WPPILOT_CONNECTION_LEVELS_OPTION);
$smoke_oauth_key = wppilot_connection_level_key('oauth', 'client-' . hash('sha256', 'claude-ai'), 3);
$smoke_pw_key = wppilot_connection_level_key('password', '6f1c2d3e-0000-4000-8000-000000000001', 3);
$smoke_use_oauth = static function (array $scopes): void {
    \WPPilot\OAuth\Middleware\reset_request_context();
    unset($GLOBALS['wp_rest_application_password_uuid']);
    \WPPilot\OAuth\Middleware\record_oauth_identity(3, $scopes, 'claude-ai');
};
$smoke_option_write = new WP_Ability('wppilot/update-option');
$smoke_read_ability = new WP_Ability('wppilot/get-post', ['annotations' => ['readonly' => true]]);

// No level: unchanged behaviour, no policy at all.
$smoke_use_oauth(['mcp']);
smoke_check(wppilot_current_token_policy() === null, 'an OAuth app with no level got a policy');
smoke_check(wppilot_agent_scope_error($smoke_option_write) === null, 'an OAuth app with no level was limited');

// mcp:read is Read Only whatever is stored, and the scope is issued, routed and shown as such.
smoke_check(\WPPilot\OAuth\normalize_authorization_scope('mcp:read') === 'mcp:read', 'mcp:read not kept at authorization');
smoke_check(\WPPilot\OAuth\normalize_authorization_scope('mcp mcp:read') === 'mcp' && \WPPilot\OAuth\normalize_authorization_scope('abilities:read') === 'mcp', 'legacy scopes changed meaning');
smoke_check(\WPPilot\OAuth\Middleware\scopes_authorize_routed_request(['mcp:read'], '/mcp/wppilot', 'POST'), 'mcp:read refused at the route');
$smoke_use_oauth(['mcp:read']);
$smoke_policy = wppilot_current_token_policy();
smoke_check(is_array($smoke_policy) && $smoke_policy['ceiling'] === 'readonly' && $smoke_policy['connection'] === $smoke_oauth_key, 'mcp:read grant not Read Only: ' . json_encode($smoke_policy));
smoke_check(wppilot_agent_profile_ceiling() === 'readonly', 'mcp:read grant did not cap the profile');
smoke_check(wppilot_current_token_id() === 0, 'an OAuth app read as an access token');

// Narrowing only, ledgered, irreversible.
$smoke_ledger_before = count(wppilot_get_change_log());
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'drafts', null, 'Claude.ai', 1) === true, 'narrowing to Drafts only refused');
$smoke_ledger = wppilot_get_change_log();
$smoke_row = $smoke_ledger[array_key_last($smoke_ledger)] ?? [];
smoke_check(count($smoke_ledger) === $smoke_ledger_before + 1 && ($smoke_row['ability'] ?? '') === 'wppilot/connection-level', 'level change not ledgered');
smoke_check(($smoke_row['rollback']['reversible'] ?? true) === false, 'level change can be undone from the ledger (that would widen it)');
$smoke_widen = wppilot_connection_level_set($smoke_oauth_key, '', null, 'Claude.ai', 1);
smoke_check($smoke_widen instanceof WP_Error && $smoke_widen->get_error_code() === 'wppilot_connection_level_widen', 'level widened');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'production', null, 'Claude.ai', 1) instanceof WP_Error, 'Drafts only widened to Production Safe');
smoke_check(count(wppilot_get_change_log()) === $smoke_ledger_before + 1, 'a refused widening was ledgered');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'drafts', null, 'Claude.ai', 1) === true && count(wppilot_get_change_log()) === $smoke_ledger_before + 1, 'a no-op save was ledgered');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'drafts', [], 'Claude.ai', 1) instanceof WP_Error, 'Custom with no category accepted');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'developer', null, 'Claude.ai', 1) instanceof WP_Error, 'unknown level accepted');
smoke_check(wppilot_connection_level_set('token:7:3', 'readonly', null, 'x', 1) instanceof WP_Error, 'a level stored for a non-connection key');

// The stored level is enforced through the token machinery, and refusals name the level.
$smoke_use_oauth(['mcp']);
smoke_check(wppilot_drafts_only_active(), 'Drafts only level not enforced on the OAuth app');
$smoke_denied = wppilot_drafts_only_scope_error($smoke_option_write);
smoke_check($smoke_denied instanceof WP_Error && str_contains($smoke_denied->get_error_message(), 'Drafts only') && str_contains($smoke_denied->get_error_message(), 'connection'), 'Drafts only refusal does not name the connection level');
smoke_check(wppilot_drafts_only_scope_error($smoke_read_ability) === null, 'Drafts only level refused a read');

// Custom: categories narrow too, and only to a subset.
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'readonly', ['content', 'seo'], 'Claude.ai', 1) === true, 'narrowing to Custom refused');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'readonly', ['content', 'seo', 'users'], 'Claude.ai', 1) instanceof WP_Error, 'Custom widened with a new category');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'readonly', null, 'Claude.ai', 1) instanceof WP_Error, 'Custom widened to every category');
smoke_check(wppilot_connection_level_set($smoke_oauth_key, 'readonly', ['content'], 'Claude.ai', 1) === true, 'Custom narrowed to a subset refused');
$smoke_seo = new WP_Ability('rank-math/get-settings', ['category' => 'seo', 'annotations' => ['readonly' => true]]);
$smoke_seo_denied = wppilot_agent_scope_error($smoke_seo);
smoke_check($smoke_seo_denied instanceof WP_Error && $smoke_seo_denied->get_error_code() === 'wppilot_connection_level_denied' && str_contains($smoke_seo_denied->get_error_message(), 'Custom'), 'Custom level let another category through: ' . ($smoke_seo_denied instanceof WP_Error ? $smoke_seo_denied->get_error_message() : 'null'));

// Fails closed: a stored level the code does not know is Read Only.
update_option(WPPILOT_CONNECTION_LEVELS_OPTION, [$smoke_oauth_key => ['ceiling' => 'everything']]);
smoke_check((wppilot_current_token_policy()['ceiling'] ?? '') === 'readonly', 'an unknown stored level did not fail closed');
update_option(WPPILOT_CONNECTION_LEVELS_OPTION, [$smoke_oauth_key => 'garbage']);
smoke_check((wppilot_current_token_policy()['ceiling'] ?? '') === 'readonly', 'a malformed stored level did not fail closed');

// Revoking the grant forgets its level; another user of the same app is a different connection.
wppilot_connection_level_forget_oauth('claude-ai', 4);
smoke_check(array_key_exists($smoke_oauth_key, get_option(WPPILOT_CONNECTION_LEVELS_OPTION)), 'another user\'s revoke forgot this level');
wppilot_connection_level_forget_oauth('claude-ai', 3);
smoke_check(wppilot_current_token_policy() === null, 'revoked grant kept its level');

// Application passwords: keyed by the UUID WordPress publishes, forgotten when deleted.
\WPPilot\OAuth\Middleware\reset_request_context();
$GLOBALS['wp_rest_application_password_uuid'] = '6f1c2d3e-0000-4000-8000-000000000001';
smoke_check(wppilot_connection_level_set($smoke_pw_key, 'readonly', null, 'Laptop', 1) === true, 'application password level refused');
smoke_check((wppilot_current_token_policy()['connection'] ?? '') === $smoke_pw_key && wppilot_agent_profile_ceiling() === 'readonly', 'application password level not enforced');
wppilot_connection_level_forget_password(3, ['uuid' => '6f1c2d3e-0000-4000-8000-000000000001']);
smoke_check(wppilot_current_token_policy() === null, 'deleted application password kept its level');
wppilot_connection_level_set($smoke_pw_key, 'readonly', null, 'Laptop', 1);
wppilot_connection_level_forget_user(3);
smoke_check(get_option(WPPILOT_CONNECTION_LEVELS_OPTION) === [], 'deleted user kept connection levels');

// A wp-admin cookie request is no connection.
unset($GLOBALS['wp_rest_application_password_uuid']);
\WPPilot\OAuth\Middleware\reset_request_context();
smoke_check(wppilot_current_connection() === null, 'a cookie request was treated as a connection');
delete_option(WPPILOT_CONNECTION_LEVELS_OPTION);
WPPilot_Test_State::$current_user_id = $smoke_level_user;

// ---------------------------------------------------------------------------------------------
// 11. Hold new abilities: a name the site has not seen stays off until an admin allows it.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/access/ability-hold.php';
if (!function_exists('wp_unregister_ability')) {
    function wp_unregister_ability(string $name): void
    {
        $GLOBALS['smoke_unregistered'][] = $name;
    }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}
$smoke_hold_registry = static function (array $names): void {
    $abilities = [];
    foreach ($names as $name) {
        $abilities[$name] = new WP_Ability($name, ['annotations' => ['readonly' => true]]);
    }
    WP_Abilities_Registry::seed_for_tests($abilities);
    $GLOBALS['smoke_unregistered'] = [];
};
delete_option(WPPILOT_ABILITY_HOLD_STATE_OPTION);
delete_option(WPPILOT_ABILITY_HOLD_OPTION);
delete_option('wppilot_ability_rules');
smoke_check(wppilot_ability_hold_enabled(), 'Hold new abilities is not on by default');

// The first pass is the baseline: an upgrade holds nothing that was already running.
$smoke_hold_registry(['wppilot/get-post', 'acme/list-orders']);
wppilot_apply_ability_policy();
smoke_check(wppilot_ability_hold_enforced() === [] && ($GLOBALS['smoke_unregistered'] ?? []) === [], 'the baseline pass held abilities that were already running');

// A plugin activation adds abilities: they are held, named after the plugin, and unregistered.
update_option(WPPILOT_ABILITY_HOLD_ACTIVATED_OPTION, ['name' => 'Acme Shop', 'at' => time()]);
$smoke_hold_registry(['wppilot/get-post', 'acme/list-orders', 'acme/delete-orders', 'acme/refund', 'wppilot/new-free-ability', 'mcp-adapter/discover-abilities']);
wppilot_apply_ability_policy();
$smoke_held = wppilot_ability_hold_enforced();
smoke_check(array_keys($smoke_held) === ['acme/delete-orders', 'acme/refund'], 'wrong abilities held: ' . json_encode(array_keys($smoke_held)));
smoke_check(wppilot_ability_hold_groups() === ['Acme Shop' => ['acme/delete-orders', 'acme/refund']], 'held abilities not grouped under the activated plugin');
smoke_check($GLOBALS['smoke_unregistered'] === ['acme/delete-orders', 'acme/refund'], 'held abilities stayed registered: ' . json_encode($GLOBALS['smoke_unregistered']));

// The agent hears why, and that it cannot fix it.
$smoke_hold_error = wppilot_enrich_disabled_ability_error(['success' => false, 'error' => "Ability 'acme/refund' not found"], ['ability_name' => 'acme/refund']);
smoke_check(str_contains((string) $smoke_hold_error['error'], 'held') && str_contains((string) $smoke_hold_error['error'], 'cannot allow it yourself'), 'held refusal does not say so');

// Seen once is known: a later pass holds nothing new, and a held one stays held.
$GLOBALS['smoke_unregistered'] = [];
wppilot_apply_ability_policy();
smoke_check(count(wppilot_ability_hold_enforced()) === 2 && $GLOBALS['smoke_unregistered'] === ['acme/delete-orders', 'acme/refund'], 'a second pass changed the holds');

// The setting off: nothing held is enforced, and new names are not held.
wppilot_ability_hold_set_enabled(false);
$smoke_hold_registry(['acme/list-orders', 'acme/delete-orders', 'acme/refund', 'other/new-thing']);
wppilot_apply_ability_policy();
smoke_check($GLOBALS['smoke_unregistered'] === [] && !wppilot_ability_is_held('acme/refund'), 'holds enforced with the setting off');
wppilot_ability_hold_set_enabled(true);
smoke_check(!isset(wppilot_ability_hold_enforced()['other/new-thing']), 'a name first seen while the setting was off was held later');

// Only an admin decides, and each decision is ledgered and irreversible.
$smoke_ledger_before = count(wppilot_get_change_log());
smoke_check(wppilot_ability_hold_decide('Acme Shop', 'approve', 1) === [], 'an unknown decision released holds');
smoke_check(wppilot_ability_hold_decide('Someone Else', 'allow', 1) === [], 'a decision for another source released holds');
smoke_check(wppilot_ability_hold_decide('Acme Shop', 'keep_off', 1) === ['acme/delete-orders', 'acme/refund'], 'Keep off did not decide the held abilities');
smoke_check(wppilot_ability_hold_enforced() === [], 'Keep off left the abilities held');
$smoke_rules = wppilot_get_ability_rules();
smoke_check(($smoke_rules['acme/refund']['disabled'] ?? false) === true && ($smoke_rules['acme/delete-orders']['disabled'] ?? false) === true, 'Keep off did not switch the abilities off');
$smoke_ledger = wppilot_get_change_log();
$smoke_row = $smoke_ledger[array_key_last($smoke_ledger)] ?? [];
smoke_check(count($smoke_ledger) === $smoke_ledger_before + 1 && ($smoke_row['ability'] ?? '') === 'wppilot/ability-hold' && ($smoke_row['rollback']['reversible'] ?? true) === false, 'hold decision not ledgered as irreversible');

// Allow releases them for good.
update_option(WPPILOT_ABILITY_HOLD_ACTIVATED_OPTION, ['name' => 'Beta Forms', 'at' => time() - 7200]);
$smoke_hold_registry(['beta/submit-form']);
wppilot_apply_ability_policy();
smoke_check(wppilot_ability_hold_groups() === ['beta' => ['beta/submit-form']], 'an old activation was credited, or the namespace not used');
smoke_check(wppilot_ability_hold_decide('beta', 'allow', 1) === ['beta/submit-form'] && !wppilot_ability_is_held('beta/submit-form'), 'Allow did not release');
$GLOBALS['smoke_unregistered'] = [];
wppilot_apply_ability_policy();
smoke_check($GLOBALS['smoke_unregistered'] === [], 'an allowed ability was unregistered');

// A tampered state fails safe to "nothing known", which re-baselines without holding.
update_option(WPPILOT_ABILITY_HOLD_STATE_OPTION, 'garbage');
smoke_check(wppilot_ability_hold_state() === ['known' => null, 'held' => []], 'malformed hold state not normalized');
delete_option(WPPILOT_ABILITY_HOLD_STATE_OPTION);
delete_option(WPPILOT_ABILITY_HOLD_OPTION);
delete_option(WPPILOT_ABILITY_HOLD_ACTIVATED_OPTION);
delete_option('wppilot_ability_rules');
WP_Abilities_Registry::seed_for_tests([]);

// ---------------------------------------------------------------------------------------------
// 12. Config as code: wp-config locks, JSON export/import, DISALLOW_FILE_EDIT / _MODS.
// ---------------------------------------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/config-locks.php';
// The Settings screen itself is not loaded here; two sections of the same shape stand in for it,
// each saving through its own callback the way the real owners do.
if (!function_exists('wppilot_settings_sections')) {
    function wppilot_settings_sections(): array
    {
        return [
            [
                'id' => 'smoke-access',
                'fields' => [
                    ['type' => 'toggle', 'name' => 'wppilot_smoke_switch', 'value' => get_option('wppilot_smoke_switch') === '1'],
                    ['type' => 'select', 'name' => 'wppilot_smoke_mode', 'value' => (string) get_option('wppilot_smoke_mode', 'open'), 'options' => ['open' => 'Open', 'strict' => 'Strict']],
                    ['type' => 'secret', 'name' => 'wppilot_smoke_key', 'value' => false],
                ],
                'save' => static function (array $post): void {
                    $GLOBALS['smoke_saves'][] = $post;
                    update_option('wppilot_smoke_switch', ($post['wppilot_smoke_switch'] ?? null) !== null ? '1' : '0');
                    update_option('wppilot_smoke_mode', (string) ($post['wppilot_smoke_mode'] ?? 'open'));
                },
            ],
            [
                'id' => 'smoke-pro',
                'fields' => [['type' => 'textarea', 'name' => 'wppilot_smoke_memory', 'value' => 'x']],
                'save' => static function (array $post): void {
                    $GLOBALS['smoke_pro_saves'][] = $post;
                },
            ],
        ];
    }
}
update_option('wppilot_smoke_switch', '0');
update_option('wppilot_smoke_mode', 'open');
smoke_check(wppilot_setting_lock_constant('wppilot_safety_profile') === 'WPPILOT_SETTING_SAFETY_PROFILE', 'lock constant name wrong');
smoke_check(wppilot_setting_lock_constant('wppilot_mcp_compact_tools') === 'WPPILOT_SETTING_MCP_COMPACT_TOOLS', 'lock constant name wrong for a nested name');

// Export: switches and choices only, never secrets or fields of other shapes.
$smoke_export = wppilot_settings_export_document();
smoke_check($smoke_export['format'] === 'gobuildwp-settings' && $smoke_export['settings'] === ['wppilot_smoke_mode' => 'open', 'wppilot_smoke_switch' => false], 'export wrong: ' . json_encode($smoke_export));

// Import: through the save callback, values checked against the field, unknown names reported.
$GLOBALS['smoke_saves'] = [];
$smoke_report = wppilot_settings_import_document((string) json_encode(['format' => 'gobuildwp-settings', 'version' => 1, 'settings' => ['wppilot_smoke_switch' => true, 'wppilot_smoke_mode' => 'everything', 'wppilot_smoke_key' => 'sk-123', 'wppilot_other' => 1]]));
smoke_check(is_array($smoke_report) && $smoke_report['applied'] === ['wppilot_smoke_switch'] && $smoke_report['rejected'] === ['wppilot_smoke_mode'] && $smoke_report['unknown'] === ['wppilot_smoke_key', 'wppilot_other'], 'import report wrong: ' . json_encode($smoke_report));
smoke_check(get_option('wppilot_smoke_switch') === '1' && get_option('wppilot_smoke_mode') === 'open', 'import did not apply through the save callback');
smoke_check(!array_key_exists('wppilot_smoke_key', $GLOBALS['smoke_saves'][0] ?? []), 'import carried a secret');
smoke_check(wppilot_settings_import_document('{"format":"something-else","version":1,"settings":{}}') instanceof WP_Error, 'a foreign document imported');
smoke_check(wppilot_settings_import_document(str_repeat(' ', WPPILOT_CONFIG_IMPORT_MAX_BYTES + 1)) instanceof WP_Error, 'an oversized document imported');
smoke_check(empty($GLOBALS['smoke_pro_saves']), 'a section with fields the import cannot reproduce was saved');

// Locks: the pinned value wins over the form, an import and drift; a bad value pins what is stored.
define('WPPILOT_SETTING_SMOKE_MODE', 'strict');
define('WPPILOT_SETTING_SMOKE_SWITCH', 'banana');
smoke_check(wppilot_setting_locks_defined(), 'lock constants not noticed');
$smoke_posted = wppilot_settings_apply_locks(['wppilot_smoke_mode' => 'open']);
smoke_check($smoke_posted === ['wppilot_smoke_mode' => 'strict', 'wppilot_smoke_switch' => '1'], 'locks did not override the posted form: ' . json_encode($smoke_posted));
smoke_check(wppilot_setting_locks_sync() === ['wppilot_smoke_mode'] && get_option('wppilot_smoke_mode') === 'strict' && get_option('wppilot_smoke_switch') === '1', 'drifted lock not saved back');
smoke_check(wppilot_setting_locks_sync() === [], 'an applied lock was saved again');
$smoke_report = wppilot_settings_import_document((string) json_encode(['format' => 'gobuildwp-settings', 'version' => 1, 'settings' => ['wppilot_smoke_switch' => false, 'wppilot_smoke_mode' => 'open']]));
smoke_check(is_array($smoke_report) && $smoke_report['locked'] === ['wppilot_smoke_switch', 'wppilot_smoke_mode'] && get_option('wppilot_smoke_mode') === 'strict', 'import changed a locked field');
smoke_check(str_contains(wppilot_setting_lock_note(['type' => 'toggle', 'name' => 'wppilot_smoke_switch', 'value' => true]), 'not one this setting accepts'), 'invalid lock not explained');
smoke_check(str_contains(wppilot_setting_lock_note(['type' => 'select', 'name' => 'wppilot_smoke_mode', 'value' => 'strict', 'options' => ['open' => 'O', 'strict' => 'S']]), 'WPPILOT_SETTING_SMOKE_MODE'), 'lock note does not name the constant');
smoke_check(wppilot_setting_lock(['type' => 'secret', 'name' => 'wppilot_smoke_key']) === null, 'a secret field was lockable');

// File constants refuse the file and code abilities on the shared gate, until the override.
$smoke_write_file = new WP_Ability('wppilot/write-file');
$smoke_install = new WP_Ability('wppilot/install-plugin');
$smoke_activate = new WP_Ability('wppilot/activate-plugin');
smoke_check(wppilot_file_constants_pre_ability_execute(['path' => 'x'], $smoke_write_file) === ['path' => 'x'], 'file ability refused with no constant set');
define('DISALLOW_FILE_EDIT', true);
$smoke_refused = wppilot_file_constants_pre_ability_execute(['path' => 'x'], $smoke_write_file);
smoke_check($smoke_refused instanceof WP_Error && $smoke_refused->get_error_code() === 'wppilot_file_constant_refused' && str_contains($smoke_refused->get_error_message(), 'DISALLOW_FILE_EDIT'), 'DISALLOW_FILE_EDIT did not refuse write-file');
smoke_check(wppilot_file_constants_pre_ability_execute([], new WP_Ability('wppilot/execute-php')) instanceof WP_Error, 'DISALLOW_FILE_EDIT did not refuse execute-php');
smoke_check(wppilot_file_constants_pre_ability_execute(['slug' => 'x'], $smoke_install) === ['slug' => 'x'], 'DISALLOW_FILE_EDIT alone refused a plugin install');
define('DISALLOW_FILE_MODS', true);
smoke_check(wppilot_file_constants_pre_ability_execute(['slug' => 'x'], $smoke_install) instanceof WP_Error, 'DISALLOW_FILE_MODS did not refuse install-plugin');
smoke_check(wppilot_file_constants_pre_ability_execute(['plugin' => 'x'], $smoke_activate) === ['plugin' => 'x'], 'DISALLOW_FILE_MODS refused an activation WordPress allows');
$smoke_profile_before = get_option(WPPILOT_SAFETY_PROFILE_OPTION, false);
update_option(WPPILOT_SAFETY_PROFILE_OPTION, 'developer');
add_filter('wppilot_pre_ability_execute', 'wppilot_file_constants_pre_ability_execute', 5, 2);
$smoke_gated = wppilot_gate_ability_call($smoke_install, ['slug' => 'x'], 'mcp', ['human_approved' => true]);
smoke_check($smoke_gated instanceof WP_Error && $smoke_gated->get_error_code() === 'wppilot_file_constant_refused', 'the shared gate did not refuse under DISALLOW_FILE_MODS, even human-approved: ' . ($smoke_gated instanceof WP_Error ? $smoke_gated->get_error_code() : 'passed'));
define('WPPILOT_ALLOW_FILE_ABILITIES', true);
smoke_check(wppilot_file_constants_pre_ability_execute(['slug' => 'x'], $smoke_install) === ['slug' => 'x'], 'WPPILOT_ALLOW_FILE_ABILITIES did not override');
$GLOBALS['wp_filter']['wppilot_pre_ability_execute'][5] = array_values(array_filter($GLOBALS['wp_filter']['wppilot_pre_ability_execute'][5] ?? [], static fn(array $entry): bool => $entry['callback'] !== 'wppilot_file_constants_pre_ability_execute'));
$smoke_profile_before === false ? delete_option(WPPILOT_SAFETY_PROFILE_OPTION) : update_option(WPPILOT_SAFETY_PROFILE_OPTION, $smoke_profile_before);
delete_option('wppilot_smoke_switch');
delete_option('wppilot_smoke_mode');

// ---------------------------------------------------------------------------------------------
// 13. Provable audit: the ledger hash chain, chain-break reports, and the signed NDJSON export.
// ---------------------------------------------------------------------------------------------
if (!defined('WPPILOT_CLOUD_KEYS_OPTION')) {
    define('WPPILOT_CLOUD_KEYS_OPTION', 'wppilot_cloud_keys');
}
require_once dirname(__DIR__) . '/includes/cloud/signing.php';
delete_option(WPPILOT_CHANGE_LOG_OPTION);
delete_option(WPPILOT_LEDGER_CHAIN_HEAD_OPTION);
delete_option(WPPILOT_CLOUD_KEYS_OPTION);
// A row recorded before this release: no hashes, counted and not judged.
update_option(WPPILOT_CHANGE_LOG_OPTION, [['id' => 'chain-legacy', 'kind' => 'change', 'ability' => 'wppilot/update-post']]);
$smoke_chain_row = static fn(string $id, array $input): array => ['id' => $id, 'kind' => 'change', 'ability' => 'wppilot/update-post', 'recorded_at' => '2026-11-13T10:00:00+00:00', 'input' => $input, 'rollback' => ['reversible' => true]];
wppilot_store_changes([
    $smoke_chain_row('chain-a', ['title' => 'Hello / world', 'price' => 1.0, 'map' => [2 => 'b', 1 => 'a'], 'empty' => []]),
    $smoke_chain_row('chain-b', ['title' => 'Ünïcode ✓']),
]);
wppilot_store_change($smoke_chain_row('chain-c', ['title' => 'third']));
$smoke_chain = wppilot_change_log_option_rows();
smoke_check(count($smoke_chain) === 4, 'chain rows not stored');
smoke_check(($smoke_chain[1]['prev_hash'] ?? '') === WPPILOT_LEDGER_CHAIN_GENESIS, 'first sealed row does not start from genesis');
smoke_check(($smoke_chain[2]['prev_hash'] ?? '') === ($smoke_chain[1]['row_hash'] ?? 'x') && ($smoke_chain[3]['prev_hash'] ?? '') === ($smoke_chain[2]['row_hash'] ?? 'x'), 'rows do not link, across one write and the next');
smoke_check(wppilot_ledger_chain_head() === ($smoke_chain[3]['row_hash'] ?? 'x'), 'head is not the newest row');
smoke_check(wppilot_ledger_chain_canonical(['b' => 1, 'a' => ['y' => 1.0, 'x' => [3, 1]]]) === '{"a":{"x":[3,1],"y":1.0},"b":1}', 'canonical JSON wrong');
$smoke_chain_report = wppilot_ledger_chain_verify();
smoke_check($smoke_chain_report['intact'] && $smoke_chain_report['sealed'] === 3 && $smoke_chain_report['unsealed'] === 1, 'fresh chain not intact: ' . json_encode($smoke_chain_report));

// Undo writes its outcome onto the row; that is not tampering.
$smoke_undone = $smoke_chain[3];
$smoke_undone['rolled_back'] = true;
$smoke_undone['rolled_back_at'] = '2026-11-13T11:00:00+00:00';
$smoke_undone['rollback_result'] = ['ok' => true];
wppilot_replace_change('chain-c', $smoke_undone);
smoke_check(wppilot_ledger_chain_verify()['intact'], 'an undo broke the chain');

// The signed export verifies offline with the pinned key, and fails without the pin or when edited.
$smoke_ndjson = '';
$smoke_export = wppilot_ledger_export(static function (string $line) use (&$smoke_ndjson): void {
    $smoke_ndjson .= $line;
});
smoke_check($smoke_export['signed'] && $smoke_export['report']['intact'], 'export not signed or not intact');
$smoke_ndjson_lines = explode("\n", rtrim($smoke_ndjson, "\n"));
smoke_check(count($smoke_ndjson_lines) === 7 && str_contains($smoke_ndjson_lines[0], '"type":"header"') && str_contains($smoke_ndjson_lines[6], '"alg":"Ed25519"'), 'export layout wrong');
$smoke_public = wppilot_ledger_public_key();
$smoke_file = tempnam(sys_get_temp_dir(), 'gbwp-ledger');
$smoke_verify = static function (string $content, string $key) use ($smoke_file): array {
    file_put_contents($smoke_file, $content);
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/verify-ledger-export.php') . ' ' . escapeshellarg($smoke_file) . ($key !== '' ? ' ' . escapeshellarg('--public-key=' . $key) : '') . ' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
};
[$smoke_code, $smoke_out] = $smoke_verify($smoke_ndjson, $smoke_public);
smoke_check($smoke_code === 0 && str_contains($smoke_out, 'VERIFIED'), 'offline verifier rejected a good export: ' . $smoke_out);
[$smoke_code, $smoke_out] = $smoke_verify($smoke_ndjson, '');
smoke_check($smoke_code === 1 && str_contains($smoke_out, 'unproven'), 'offline verifier accepted an unpinned key');
[$smoke_code, $smoke_out] = $smoke_verify($smoke_ndjson, base64_encode(str_repeat('k', 32)));
smoke_check($smoke_code === 1 && str_contains($smoke_out, 'pinned public key'), 'offline verifier accepted the wrong key');
[$smoke_code, $smoke_out] = $smoke_verify(str_replace('"title":"third"', '"title":"forged"', $smoke_ndjson), $smoke_public);
smoke_check($smoke_code === 1 && str_contains($smoke_out, 'chain break at row 4') && str_contains($smoke_out, 'changed after it was signed'), 'offline verifier missed an edited row: ' . $smoke_out);
[$smoke_code, $smoke_out] = $smoke_verify(implode("\n", array_slice($smoke_ndjson_lines, 0, 6)) . "\n", $smoke_public);
smoke_check($smoke_code === 1 && str_contains($smoke_out, 'truncated'), 'offline verifier accepted a truncated export');
unlink($smoke_file);

// Tampering in the database is reported as a chain break at the row, never repaired.
$smoke_good = wppilot_change_log_option_rows();
$smoke_tamper = static function (callable $edit) use ($smoke_good): array {
    update_option(WPPILOT_CHANGE_LOG_OPTION, $edit($smoke_good));
    $report = wppilot_ledger_chain_verify();
    update_option(WPPILOT_CHANGE_LOG_OPTION, $smoke_good);

    return $report;
};
$smoke_report = $smoke_tamper(static function (array $rows): array {
    $rows[2]['input']['title'] = 'edited in phpMyAdmin';
    return $rows;
});
smoke_check(!$smoke_report['intact'] && $smoke_report['break_count'] === 1 && $smoke_report['breaks'][0]['row'] === 3 && str_contains($smoke_report['breaks'][0]['reason'], 'changed after'), 'edited row not reported: ' . json_encode($smoke_report['breaks']));
smoke_check(str_contains(wppilot_ledger_chain_summary($smoke_report), 'Chain break at row 3'), 'summary does not name the row');
$smoke_report = $smoke_tamper(static function (array $rows): array {
    unset($rows[2]);
    return array_values($rows);
});
smoke_check(!$smoke_report['intact'] && $smoke_report['breaks'][0]['row'] === 3 && str_contains($smoke_report['breaks'][0]['reason'], 'deleted'), 'deleted middle row not reported');
$smoke_report = $smoke_tamper(static fn(array $rows): array => array_slice($rows, 0, 3));
smoke_check(!$smoke_report['intact'] && str_contains($smoke_report['breaks'][0]['reason'], 'newest rows were deleted'), 'deleted newest row not reported');
$smoke_report = $smoke_tamper(static function (array $rows): array {
    $rows[] = ['id' => 'hand-made', 'ability' => 'wppilot/update-post'];
    return $rows;
});
smoke_check(!$smoke_report['intact'] && $smoke_report['breaks'][0]['row'] === 5 && str_contains($smoke_report['breaks'][0]['reason'], 'no hash'), 'hand-inserted row not reported');
$smoke_report = $smoke_tamper(static fn(array $rows): array => array_slice($rows, 2));
smoke_check($smoke_report['intact'] && $smoke_report['sealed'] === 2, 'pruning the oldest rows read as a break');
smoke_check(wppilot_ledger_chain_verify()['intact'], 'restoring the rows did not restore the chain');
delete_option(WPPILOT_CHANGE_LOG_OPTION);
delete_option(WPPILOT_LEDGER_CHAIN_HEAD_OPTION);
delete_option(WPPILOT_CLOUD_KEYS_OPTION);

// ---------------------------------------------------------------------------------------------
// Read Mode: GET rest-request and read WP-CLI commands run, writes do not, and turning Read Mode
// on never opens what the site's own profile keeps closed.
// ---------------------------------------------------------------------------------------------
WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';
WPPilot_Test_State::$options[WPPILOT_READ_MODE_OPTION] = '1';
$smoke_rest = new WP_Ability('wppilot/rest-request', ['annotations' => ['readonly' => false, 'destructive' => false]]);
$smoke_cli = new WP_Ability('wppilot/run-wp-cli', ['annotations' => ['readonly' => false, 'destructive' => true]]);
$smoke_write = new WP_Ability('wppilot/update-post', ['annotations' => ['readonly' => false]]);
smoke_check(wppilot_safety_profile_allows_ability($smoke_rest), 'Read Mode hid rest-request on Developer');
smoke_check(wppilot_safety_profile_allows_ability($smoke_cli), 'Read Mode hid run-wp-cli on Developer');
smoke_check(!wppilot_safety_profile_allows_ability($smoke_write), 'Read Mode let a plain write through');
$smoke_gate = static fn(WP_Ability $ability, array $input): mixed => wppilot_safety_filter_ability_permission(true, $ability->get_name(), $input, $ability);
smoke_check($smoke_gate($smoke_rest, ['route' => '/wp/v2/posts']) === true, 'Read Mode refused a GET with no method');
smoke_check($smoke_gate($smoke_rest, ['method' => 'get', 'route' => '/wp/v2/posts']) === true, 'Read Mode refused a lower-case GET');
foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', ''] as $smoke_method) {
    $smoke_denied = $smoke_gate($smoke_rest, ['method' => $smoke_method, 'route' => '/wp/v2/posts']);
    smoke_check($smoke_denied instanceof WP_Error && $smoke_denied->get_error_code() === 'wppilot_read_mode', "Read Mode let rest-request {$smoke_method} through");
}
smoke_check($smoke_gate($smoke_cli, ['args' => ['plugin', 'list']]) === true, 'Read Mode refused wp plugin list');
smoke_check($smoke_gate($smoke_cli, ['args' => ['option', 'get', 'blogname']]) === true, 'Read Mode refused wp option get');
foreach ([['plugin', 'install', 'x'], ['config', 'get', 'DB_PASSWORD'], ['eval', 'echo 1;'], ['plugin', 'list', '--exec=echo 1;'], ['plugin', 7], []] as $smoke_args) {
    $smoke_denied = $smoke_gate($smoke_cli, ['args' => $smoke_args]);
    smoke_check($smoke_denied instanceof WP_Error && $smoke_denied->get_error_code() === 'wppilot_read_mode', 'Read Mode let wp ' . json_encode($smoke_args) . ' through');
}
smoke_check(wppilot_safety_filter_ability_permission(true, 'wppilot/update-post', [], $smoke_write) instanceof WP_Error, 'Read Mode gate let a write through');
// On Read Only or Production Safe, Read Mode does not open what the profile itself refuses.
WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
smoke_check(!wppilot_safety_profile_allows_ability($smoke_rest), 'Read Mode opened rest-request on a Read Only site');
smoke_check(!wppilot_safety_profile_allows_ability($smoke_cli), 'Read Mode opened run-wp-cli on a Read Only site');
WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
smoke_check(
    wppilot_safety_profile_allows_ability($smoke_cli) === (wppilot_ability_risk($smoke_cli) !== 'critical'),
    'Read Mode judged run-wp-cli by something other than the Production Safe profile',
);
// Nothing outside the input-gated pair reads as a read.
smoke_check(!wppilot_read_mode_input_is_read('wppilot/update-post', []), 'an ability outside the input-gated pair read as a read');
unset(WPPilot_Test_State::$options[WPPILOT_READ_MODE_OPTION]);
WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';
smoke_check(wppilot_safety_profile_allows_ability($smoke_cli) && wppilot_safety_filter_ability_permission(true, 'wppilot/run-wp-cli', ['args' => ['plugin', 'install', 'x']], $smoke_cli) === true, 'the input gate applied with Read Mode off');

// The db-write kit's three abilities are critical here (moved from the kit test, which is exported).
foreach (['wppilot/db-insert-row', 'wppilot/db-update-rows', 'wppilot/db-delete-rows'] as $smoke_name) {
    smoke_check(wppilot_ability_name_is_critical($smoke_name), "{$smoke_name} is not critical in safety.php");
}

fwrite(STDOUT, "Connection safety smoke checks passed.\n");
