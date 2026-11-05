<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * Virtual patches: narrow rules, sent by GoBuildWP Cloud, that stop a known exploit against a
 * plugin or theme version this site still runs, until it is updated.
 *
 * A rule is data, never code: which component and versions it is for, which endpoint (an
 * admin-ajax or admin-post action, a REST route, a path), optionally which request field and a
 * test on its value, which methods, and who it applies to. The only pattern language is a short,
 * checked regular expression, run with a low backtracking limit on a capped value.
 *
 * The site checks the installed version itself and applies only the rules that still match it, so
 * a rule stops on the update, before Cloud hears about it. The full set is stored unautoloaded;
 * the guard reads only the small autoloaded list of rules active right now.
 */

const OPT_VPATCHES = 'gobuildwp_kit_sp_vpatches';
const OPT_VPATCHES_ACTIVE = 'gobuildwp_kit_sp_vpatches_active';

const MAX_VPATCHES = 200;
const VP_MAX_PATTERN = 200;
const VP_MAX_TEXT = 200;
const VP_MAX_VALUE_BYTES = 4096;
const VP_MAX_VALUES = 100;
const VP_MAX_JSON_BYTES = 1_048_576;
const VP_BACKTRACK_LIMIT = 10000;

const VP_ENDPOINTS = ['ajax', 'admin_post', 'rest', 'path', 'any'];
const VP_TESTS = ['present', 'equals', 'contains', 'pattern', 'longer_than', 'not_integer'];
const VP_WHO = ['anyone', 'anonymous', 'without_capability'];
const VP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

// ---------------------------------------------------------------- validation

/**
 * Check one rule from Cloud and return it in canonical form.
 *
 * @param mixed $rule
 * @return array<string, mixed>|WP_Error
 */
function sanitize_virtual_patch(mixed $rule): array|WP_Error
{
    $bad = static fn(string $what): WP_Error => new WP_Error('gobuildwp_vpatch_invalid', sprintf('Invalid virtual patch: %s.', $what));
    if (!is_array($rule)) {
        return $bad('a rule must be an object');
    }
    $id = $rule['id'] ?? null;
    if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
        return $bad('id must be 1-64 letters, digits, - or _');
    }
    $title = $rule['title'] ?? '';
    if (!is_string($title) || strlen($title) > VP_MAX_TEXT) {
        return $bad("$id: title must be text of at most " . VP_MAX_TEXT . ' characters');
    }
    $revision = $rule['revision'] ?? 1;
    if (!is_int($revision) || $revision < 1) {
        return $bad("$id: revision must be a positive integer");
    }

    $c = $rule['component'] ?? null;
    if (!is_array($c)) {
        return $bad("$id: component is required");
    }
    $type = $c['type'] ?? null;
    $slug = $c['slug'] ?? null;
    if (!in_array($type, ['plugin', 'theme'], true)) {
        return $bad("$id: component.type must be plugin or theme");
    }
    if (!is_string($slug) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $slug) !== 1 || str_contains($slug, '..')) {
        return $bad("$id: component.slug is not a valid slug");
    }
    $from = $c['from'] ?? '*';
    $to = $c['to'] ?? null;
    foreach (['from' => $from, 'to' => $to] as $k => $v) {
        if (!is_string($v) || ($v !== '*' && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$/', $v) !== 1)) {
            return $bad("$id: component.$k must be a version or *");
        }
    }
    // A rule with no upper bound would never stop on an update.
    if ($to === '*') {
        return $bad("$id: component.to must be a version: a rule has to stop applying once the component is updated");
    }
    $from_inclusive = $c['from_inclusive'] ?? true;
    $to_inclusive = $c['to_inclusive'] ?? false;
    if (!is_bool($from_inclusive) || !is_bool($to_inclusive)) {
        return $bad("$id: component.from_inclusive and to_inclusive must be true or false");
    }

    $m = $rule['match'] ?? null;
    if (!is_array($m)) {
        return $bad("$id: match is required");
    }
    $endpoint = $m['endpoint'] ?? null;
    if (!is_array($endpoint) || !in_array($endpoint['kind'] ?? null, VP_ENDPOINTS, true)) {
        return $bad("$id: match.endpoint.kind must be one of " . implode(', ', VP_ENDPOINTS));
    }
    $kind = $endpoint['kind'];
    $value = $endpoint['value'] ?? '';
    if (!is_string($value) || strlen($value) > VP_MAX_TEXT) {
        return $bad("$id: match.endpoint.value must be text of at most " . VP_MAX_TEXT . ' characters');
    }
    if ($kind === 'any') {
        $value = '';
    } elseif ($kind === 'ajax' || $kind === 'admin_post') {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $value) !== 1) {
            return $bad("$id: match.endpoint.value must be an action name");
        }
    } else {
        $value = vp_normalize_path($value);
        if ($value === '/' || preg_match('#^/[A-Za-z0-9/._~@:+-]+$#', $value) !== 1) {
            return $bad("$id: match.endpoint.value must be a path starting with / (not / alone)");
        }
        $value = strtolower($value);
    }

    $methods = $m['methods'] ?? [];
    if (!is_array($methods) || count($methods) > count(VP_METHODS)) {
        return $bad("$id: match.methods must be a list");
    }
    $clean_methods = [];
    foreach ($methods as $method) {
        if (!is_string($method) || !in_array(strtoupper($method), VP_METHODS, true)) {
            return $bad("$id: match.methods may only hold " . implode(', ', VP_METHODS));
        }
        $clean_methods[strtoupper($method)] = true;
    }

    $param = $m['param'] ?? null;
    $clean_param = null;
    if ($param !== null) {
        $clean_param = sanitize_virtual_patch_param($id, $param);
        if ($clean_param instanceof WP_Error) {
            return $clean_param;
        }
    }
    // Without an endpoint the rule must at least name a field, or it would block the whole site.
    if ($kind === 'any' && $clean_param === null) {
        return $bad("$id: a rule for any endpoint must name a request field");
    }

    $who = $m['who'] ?? 'anyone';
    if (!in_array($who, VP_WHO, true)) {
        return $bad("$id: match.who must be one of " . implode(', ', VP_WHO));
    }
    $capability = '';
    if ($who === 'without_capability') {
        $capability = $m['capability'] ?? '';
        if (!is_string($capability) || preg_match('/^[a-z0-9_]{1,64}$/', $capability) !== 1) {
            return $bad("$id: match.capability must be a capability name");
        }
    }

    return [
        'id' => $id,
        'revision' => $revision,
        'title' => sanitize_text($title),
        'component' => [
            'type' => $type,
            'slug' => $slug,
            'from' => $from,
            'from_inclusive' => $from_inclusive,
            'to' => $to,
            'to_inclusive' => $to_inclusive,
        ],
        'match' => [
            'endpoint' => ['kind' => $kind, 'value' => $value],
            'methods' => array_keys($clean_methods),
            'param' => $clean_param,
            'who' => $who,
            'capability' => $capability,
        ],
    ];
}

/**
 * @return array{name: list<string>, in: string, test: string, value: string}|WP_Error
 */
function sanitize_virtual_patch_param(string $id, mixed $param): array|WP_Error
{
    $bad = static fn(string $what): WP_Error => new WP_Error('gobuildwp_vpatch_invalid', sprintf('Invalid virtual patch: %s: %s.', $id, $what));
    if (!is_array($param)) {
        return $bad('match.param must be an object');
    }
    $name = $param['name'] ?? null;
    $path = is_string($name) ? vp_param_path($name) : null;
    if ($path === null) {
        return $bad('match.param.name must be a field name like file or data[file]');
    }
    $in = $param['in'] ?? 'any';
    if (!in_array($in, ['any', 'query', 'body'], true)) {
        return $bad('match.param.in must be any, query or body');
    }
    $test = $param['test'] ?? 'present';
    if (!in_array($test, VP_TESTS, true)) {
        return $bad('match.param.test must be one of ' . implode(', ', VP_TESTS));
    }
    $value = $param['value'] ?? '';
    if ($test === 'longer_than') {
        if (!is_int($value) || $value < 0 || $value > 1000000) {
            return $bad('match.param.value must be a length for longer_than');
        }
        $value = (string) $value;
    } elseif ($test === 'equals' || $test === 'contains' || $test === 'pattern') {
        $max = $test === 'pattern' ? VP_MAX_PATTERN : VP_MAX_TEXT;
        if (!is_string($value) || $value === '' || strlen($value) > $max) {
            return $bad("match.param.value must be 1-$max characters for $test");
        }
        if ($test === 'pattern') {
            $error = vp_pattern_problem($value);
            if ($error !== null) {
                return $bad('match.param.value ' . $error);
            }
        }
    } else {
        $value = '';
    }
    return ['name' => $path, 'in' => $in, 'test' => $test, 'value' => $value];
}

/**
 * `file` → ['file'], `data[file]` → ['data', 'file']; null when the name isn't one of those.
 *
 * @return list<string>|null
 */
function vp_param_path(string $name): ?array
{
    if (preg_match('/^([A-Za-z0-9_.-]{1,64})((?:\[[A-Za-z0-9_.-]{1,64}\]){0,4})$/', $name, $m) !== 1) {
        return null;
    }
    $path = [$m[1]];
    if ($m[2] !== '' && preg_match_all('/\[([A-Za-z0-9_.-]{1,64})\]/', $m[2], $keys) > 0) {
        foreach ($keys[1] as $key) {
            $path[] = $key;
        }
    }
    return $path;
}

/**
 * Why a pattern from Cloud is refused, or null if it is acceptable.
 *
 * Only plain matching is allowed: no inline options or groups that start with (?, which cover
 * look-arounds, recursion, callouts and option changes, and no back-references.
 */
function vp_pattern_problem(string $pattern): ?string
{
    if (str_contains($pattern, '(?') || str_contains($pattern, '(*')) {
        return 'may not use (? or (* groups';
    }
    if (preg_match('/\\\\(?:[1-9gkR]|[pPX])/', $pattern) === 1) {
        return 'may not use back-references, recursion or Unicode classes';
    }
    if (str_contains($pattern, "\0")) {
        return 'may not contain NUL';
    }
    $result = @preg_match(vp_regex($pattern), '');
    if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
        return 'is not a valid pattern';
    }
    return null;
}

function vp_regex(string $pattern): string
{
    return '~' . str_replace('~', '\\~', $pattern) . '~i';
}

// ---------------------------------------------------------------- storing and version checks

/**
 * Replace the whole rule set. Every rule is checked first; one bad rule refuses the set.
 *
 * @param array<mixed> $rules
 * @return array<string, mixed>|WP_Error
 */
function set_virtual_patches(array $rules, ?int $now = null): array|WP_Error
{
    if (!array_is_list($rules) || count($rules) > MAX_VPATCHES) {
        return new WP_Error('gobuildwp_vpatch_invalid', 'Invalid virtual patch: rules must be a list of at most ' . MAX_VPATCHES . ' rules.');
    }
    $clean = [];
    foreach ($rules as $rule) {
        $one = sanitize_virtual_patch($rule);
        if ($one instanceof WP_Error) {
            return $one;
        }
        if (isset($clean[$one['id']])) {
            return new WP_Error('gobuildwp_vpatch_invalid', sprintf('Invalid virtual patch: id %s appears twice.', $one['id']));
        }
        $clean[$one['id']] = $one;
    }
    $previous = stored_virtual_patches();
    $stored = ['rules' => array_values($clean), 'set_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time())];
    if ($stored['rules'] !== $previous['rules']) {
        update_option(OPT_VPATCHES, $stored, false);
        recheck_virtual_patches();
        record_event('settings', 'changed', '', null, '', sprintf('Virtual patches: %d rules', count($clean)));
    } else {
        recheck_virtual_patches();
    }
    return virtual_patch_state();
}

/** @return array{rules: list<array<string, mixed>>, set_at: string|null} */
function stored_virtual_patches(): array
{
    $stored = get_option(OPT_VPATCHES, []);
    $rules = is_array($stored) && is_array($stored['rules'] ?? null) ? array_values($stored['rules']) : [];
    return ['rules' => $rules, 'set_at' => is_array($stored) && is_string($stored['set_at'] ?? null) ? $stored['set_at'] : null];
}

/**
 * The rules active right now: the autoloaded list the guard reads on every request.
 *
 * @return list<array<string, mixed>>
 */
function active_virtual_patches(): array
{
    $active = get_option(OPT_VPATCHES_ACTIVE, []);
    return is_array($active) && is_array($active['rules'] ?? null) ? array_values($active['rules']) : [];
}

/** Whether any rules are stored at all, active or not: then updates have to recheck them. */
function has_virtual_patches(): bool
{
    $active = get_option(OPT_VPATCHES_ACTIVE, []);
    return is_array($active) && (int) ($active['stored'] ?? 0) > 0;
}

/**
 * Recompute which stored rules apply to the versions installed now. Runs when the set changes and
 * after any plugin or theme is updated, installed or deleted, never on an ordinary request.
 *
 * @param (callable(string, string): ?string)|null $version_of
 */
function recheck_virtual_patches(?callable $version_of = null): void
{
    $version_of ??= __NAMESPACE__ . '\\installed_version';
    $stored = stored_virtual_patches()['rules'];
    $active = [];
    foreach ($stored as $rule) {
        $c = $rule['component'];
        $installed = $version_of($c['type'], $c['slug']);
        if ($installed !== null && version_in_range($installed, $c)) {
            $active[] = ['id' => $rule['id'], 'title' => $rule['title'], 'match' => $rule['match']];
        }
    }
    $next = ['stored' => count($stored), 'rules' => $active];
    if (get_option(OPT_VPATCHES_ACTIVE, null) !== $next) {
        update_option(OPT_VPATCHES_ACTIVE, $next, true);
    }
}

/**
 * The installed version of a plugin (by folder, or file name for a single-file plugin) or theme
 * (by stylesheet), or null when it isn't installed.
 */
function installed_version(string $type, string $slug): ?string
{
    if ($type === 'theme') {
        if (!function_exists('wp_get_theme')) {
            return null;
        }
        $theme = wp_get_theme($slug);
        return $theme->exists() ? (string) $theme->get('Version') : null;
    }
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach (get_plugins() as $file => $headers) {
        $file = str_replace('\\', '/', (string) $file);
        $slash = strpos($file, '/');
        $plugin_slug = $slash !== false ? substr($file, 0, $slash) : (string) preg_replace('/\.php$/i', '', $file);
        if ($plugin_slug === $slug) {
            return (string) (((array) $headers)['Version'] ?? '');
        }
    }
    return null;
}

/**
 * Whether $version falls in the rule's range, with PHP's version_compare (the order WordPress uses).
 * An empty version can't be placed, so the rule applies: the component is installed and may be old.
 *
 * @param array{from: string, from_inclusive: bool, to: string, to_inclusive: bool} $c
 */
function version_in_range(string $version, array $c): bool
{
    if ($version === '') {
        return true;
    }
    if ($c['from'] !== '*') {
        $cmp = version_compare($version, $c['from']);
        if ($cmp < 0 || ($cmp === 0 && !$c['from_inclusive'])) {
            return false;
        }
    }
    if ($c['to'] !== '*') {
        $cmp = version_compare($version, $c['to']);
        if ($cmp > 0 || ($cmp === 0 && !$c['to_inclusive'])) {
            return false;
        }
    }
    return true;
}

/**
 * What Cloud and the status read see: every stored rule, whether it applies, and the installed version.
 *
 * @return array{stored: int, active: int, set_at: string|null, rules: list<array{id: string, revision: int, title: string, type: string, slug: string, installed_version: string|null, active: bool}>}
 */
function virtual_patch_state(): array
{
    $stored = stored_virtual_patches();
    $active = [];
    foreach (active_virtual_patches() as $rule) {
        $active[(string) ($rule['id'] ?? '')] = true;
    }
    $rules = [];
    foreach ($stored['rules'] as $rule) {
        $c = $rule['component'];
        $rules[] = [
            'id' => (string) $rule['id'],
            'revision' => (int) $rule['revision'],
            'title' => (string) $rule['title'],
            'type' => (string) $c['type'],
            'slug' => (string) $c['slug'],
            'installed_version' => installed_version((string) $c['type'], (string) $c['slug']),
            'active' => isset($active[$rule['id']]),
        ];
    }
    return ['stored' => count($stored['rules']), 'active' => count($active), 'set_at' => $stored['set_at'], 'rules' => $rules];
}

/**
 * Updates, installs and deletes change versions: recheck the rules after each. Hooked whenever
 * rules are stored, also under WP-CLI, which updates plugins too.
 */
function install_virtual_patch_hooks(): void
{
    $recheck = static function (): void {
        recheck_virtual_patches();
    };
    add_action('upgrader_process_complete', $recheck, 99, 0);
    add_action('deleted_plugin', $recheck, 99, 0);
    add_action('deleted_theme', $recheck, 99, 0);
}

// ---------------------------------------------------------------- matching

/**
 * The parts of a request the rules look at.
 *
 * @param array<string, mixed> $server
 * @param array<mixed> $get
 * @param array<mixed> $post The form body ($_POST), or a decoded JSON body when $json_body is true.
 * @param bool $json_body Whether $post was decoded from a JSON body. WordPress never sees a JSON body
 *   in $_POST, so admin-ajax.php's `$_REQUEST['action']` and parse_request's `rest_route` query var
 *   can only come from the form body or the query string; a JSON body must not be allowed to
 *   choose a different handler than the one WordPress will run.
 * @return array{method: string, path: string, script: string, action: string, rest_route: string|null, query: array<mixed>, body: array<mixed>}
 */
function vp_request(array $server, array $get, array $post, string $rest_prefix = '/wp-json/', bool $json_body = false): array
{
    $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
    $path = strtolower(vp_normalize_path(rawurldecode((string) strtok($uri, '?'))));
    // admin-ajax.php and admin-post.php dispatch on $_REQUEST['action']: the form body wins over
    // the query string, and a JSON body counts for nothing.
    $form = $json_body ? [] : $post;
    $action = $form['action'] ?? $get['action'] ?? '';
    // Only the front controller serves REST. A rest_route or /wp-json/ on admin-ajax.php,
    // xmlrpc.php or any other script is not a REST call and must not be treated as one.
    $front = basename(is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : 'index.php') === 'index.php';
    $rest_prefix = strtolower($rest_prefix);
    $rest_route = null;
    // parse_request lets a public query var in the form body or query string override the route
    // the permalink would give, so `/wp-json/a?rest_route=/b` serves /b. Read them in WordPress's
    // order: form body, then query string, then the path.
    $route_var = $form['rest_route'] ?? $get['rest_route'] ?? null;
    if ($front && is_string($route_var)) {
        $rest_route = strtolower(vp_normalize_path(rawurldecode($route_var)));
    } elseif ($front && str_starts_with($path, $rest_prefix)) {
        $rest_route = '/' . substr($path, strlen($rest_prefix));
    }
    return [
        'method' => is_string($server['REQUEST_METHOD'] ?? null) ? strtoupper($server['REQUEST_METHOD']) : 'GET',
        'path' => $path,
        'script' => basename($path),
        'action' => is_string($action) ? $action : '',
        'rest_route' => $rest_route === null ? null : rtrim($rest_route, '/'),
        'query' => $get,
        'body' => $post,
    ];
}

/**
 * Collapse repeated slashes and drop `.` and `..` segments, so `/a//b/./c` can't slip past `/a/b/c`.
 */
function vp_normalize_path(string $path): string
{
    $out = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $segment;
    }
    return '/' . implode('/', $out);
}

/**
 * Whether the request matches the rule, ignoring who sends it.
 *
 * @param array<string, mixed> $rule An active rule: id, title, match.
 * @param array{method: string, path: string, script: string, action: string, rest_route: string|null, query: array<mixed>, body: array<mixed>} $req
 */
function vp_matches(array $rule, array $req): bool
{
    $m = $rule['match'];
    if ($m['methods'] !== [] && !in_array($req['method'], $m['methods'], true)) {
        return false;
    }
    $value = (string) $m['endpoint']['value'];
    $hit = match ($m['endpoint']['kind']) {
        'ajax' => $req['script'] === 'admin-ajax.php' && str_contains($req['path'], '/wp-admin/') && $req['action'] === $value,
        'admin_post' => $req['script'] === 'admin-post.php' && str_contains($req['path'], '/wp-admin/') && $req['action'] === $value,
        'rest' => $req['rest_route'] !== null && ($req['rest_route'] === $value || str_starts_with($req['rest_route'], $value . '/')),
        'path' => str_ends_with($req['path'], $value),
        'any' => true,
        default => false,
    };
    if (!$hit) {
        return false;
    }
    return $m['param'] === null || vp_param_matches($m['param'], $req);
}

/**
 * @param array{name: list<string>, in: string, test: string, value: string} $param
 * @param array{query: array<mixed>, body: array<mixed>} $req
 */
function vp_param_matches(array $param, array $req): bool
{
    $sources = match ($param['in']) {
        'query' => [$req['query']],
        'body' => [$req['body']],
        default => [$req['query'], $req['body']],
    };
    foreach ($sources as $source) {
        $found = vp_dig($source, $param['name']);
        if ($found === null) {
            continue;
        }
        if ($param['test'] === 'present') {
            return true;
        }
        $values = [];
        $budget = VP_MAX_VALUES;
        vp_leaves($found, $values, $budget, 0);
        foreach ($values as $value) {
            if (vp_value_matches($param['test'], $param['value'], $value)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param array<mixed> $source
 * @param list<string> $path
 */
function vp_dig(array $source, array $path): mixed
{
    $at = $source;
    foreach ($path as $key) {
        if (!is_array($at) || !array_key_exists($key, $at)) {
            return null;
        }
        $at = $at[$key];
    }
    return $at;
}

/**
 * @param list<string> $out
 */
function vp_leaves(mixed $value, array &$out, int &$budget, int $depth): void
{
    if ($budget <= 0 || $depth > 5) {
        return;
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            vp_leaves($v, $out, $budget, $depth + 1);
        }
        return;
    }
    if (is_scalar($value)) {
        $out[] = (string) $value;
        $budget--;
    }
}

/**
 * One test on one value. The value is capped, and also tried once decoded, the way the firewall
 * catches double-encoded attacks. A pattern that fails to run (the backtracking limit, say) counts
 * as a match: the request already reached the vulnerable endpoint and field, and a value built to
 * exhaust the matcher is not one a real visitor sends.
 */
function vp_value_matches(string $test, string $expected, string $value): bool
{
    $candidates = [$value];
    if (str_contains($value, '%')) {
        $candidates[] = rawurldecode($value);
    }
    foreach ($candidates as $candidate) {
        $hit = match ($test) {
            'equals' => $candidate === $expected,
            'contains' => stripos(substr($candidate, 0, VP_MAX_VALUE_BYTES), $expected) !== false,
            'longer_than' => strlen($candidate) > (int) $expected,
            'not_integer' => preg_match('/^-?\d{1,19}$/', trim($candidate)) !== 1,
            'pattern' => vp_pattern_matches($expected, substr($candidate, 0, VP_MAX_VALUE_BYTES)),
            default => false,
        };
        if ($hit) {
            return true;
        }
    }
    return false;
}

function vp_pattern_matches(string $pattern, string $subject): bool
{
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', (string) VP_BACKTRACK_LIMIT);
    try {
        $result = @preg_match(vp_regex($pattern), $subject);
        $error = preg_last_error();
    } finally {
        if ($limit !== false) {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }
    return $result === 1 || $result === false || $error !== PREG_NO_ERROR;
}

/**
 * Whether the rule applies to this sender. Administrators are never blocked, as everywhere in
 * website protection.
 *
 * @param array<string, mixed> $rule
 * @param callable(string): bool $can The sender's capability check (false for everything when anonymous).
 */
function vp_applies_to(array $rule, bool $signed_in, callable $can): bool
{
    if ($signed_in && $can('manage_options')) {
        return false;
    }
    return match ($rule['match']['who']) {
        'anonymous' => !$signed_in,
        'without_capability' => !$signed_in || !$can((string) $rule['match']['capability']),
        default => true,
    };
}

// ---------------------------------------------------------------- the guard

/** @var list<array<string, mixed>>|null Rules hit by a REST call that carries credentials. */
$GLOBALS['gobuildwp_sp_vp_deferred'] = null;

/**
 * Apply the active rules to this request. Most requests match no endpoint and cost a few string
 * compares; only a hit looks at the session cookie.
 *
 * @param list<array<string, mixed>> $rules
 * @param array<string, mixed> $settings
 */
function guard_virtual_patches(array $rules, array $settings): void
{
    $body = $_POST;
    $json_body = false;
    if ($body === [] && vp_is_json($_SERVER)) {
        $raw = file_get_contents('php://input', false, null, 0, VP_MAX_JSON_BYTES + 1);
        $body = vp_json_body(is_string($raw) ? $raw : '');
        $json_body = true;
    }
    $req = vp_request($_SERVER, $_GET, $body, rest_path_prefix(), $json_body);
    $hits = array_values(array_filter($rules, static fn(array $rule): bool => vp_matches($rule, $req)));
    if ($hits === []) {
        return;
    }
    $ip = client_ip($_SERVER, $settings['ip_source']);
    if ($ip !== '' && ip_in_list($ip, $settings['allowlist'])) {
        return;
    }
    $has_auth_header = !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['PHP_AUTH_USER']);
    if ($req['rest_route'] !== null && $has_auth_header) {
        // WordPress hasn't checked the application password yet: decide once it has.
        $GLOBALS['gobuildwp_sp_vp_deferred'] = ['rules' => $hits, 'ip' => $ip, 'path' => $req['path']];
        add_filter('rest_authentication_errors', __NAMESPACE__ . '\\filter_rest_virtual_patches', 100, 1);
        // WordPress serves REST on parse_request and exits; a request that gets past it was never
        // authenticated as REST, so the rules apply as they would without the header.
        add_action('parse_request', __NAMESPACE__ . '\\enforce_vp_deferred', PHP_INT_MAX, 0);
        add_action('admin_init', __NAMESPACE__ . '\\enforce_vp_deferred', 0, 0);
        add_action('login_init', __NAMESPACE__ . '\\enforce_vp_deferred', 0, 0);
        return;
    }
    $user_id = cookie_user_id();
    $can = static fn(string $cap): bool => $user_id > 0 && user_can($user_id, $cap);
    foreach ($hits as $rule) {
        if (vp_applies_to($rule, $user_id > 0, $can)) {
            act(vp_decision($rule), $ip, $req['path']);
            return;
        }
    }
}

/**
 * @param array<string, mixed> $rule
 * @return array{action: string, type: string, rule: string, detail: string}
 */
function vp_decision(array $rule): array
{
    $title = (string) ($rule['title'] ?? '');
    return [
        'action' => 'block',
        'type' => 'firewall',
        'rule' => 'vpatch:' . $rule['id'],
        'detail' => 'Virtual patch' . ($title !== '' ? ': ' . $title : ''),
    ];
}

/** The signed-in user's id from the session cookie, without setting the current user. */
function cookie_user_id(): int
{
    if (!defined('LOGGED_IN_COOKIE') || empty($_COOKIE[LOGGED_IN_COOKIE]) || !function_exists('wp_validate_auth_cookie')) {
        return 0;
    }
    return (int) wp_validate_auth_cookie('', 'logged_in');
}

/** @param array<string, mixed> $server */
function vp_is_json(array $server): bool
{
    $type = $server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? '';
    return is_string($type) && str_contains(strtolower($type), 'json');
}

/**
 * A JSON request body as parameters, so body rules see what REST endpoints read from JSON.
 * Too large or not an object/array: nothing.
 *
 * @return array<mixed>
 */
function vp_json_body(string $raw): array
{
    if ($raw === '' || strlen($raw) > VP_MAX_JSON_BYTES) {
        return [];
    }
    $data = json_decode($raw, true, 64);
    return is_array($data) ? $data : [];
}

/** Applies virtual-patch rules whose decision waited for REST authentication that never came. */
function enforce_vp_deferred(): void
{
    $d = $GLOBALS['gobuildwp_sp_vp_deferred'] ?? null;
    if (!is_array($d)) {
        return;
    }
    $GLOBALS['gobuildwp_sp_vp_deferred'] = null;
    $user_id = cookie_user_id();
    $can = static fn(string $cap): bool => $user_id > 0 && user_can($user_id, $cap);
    foreach ($d['rules'] as $rule) {
        if (vp_applies_to($rule, $user_id > 0, $can)) {
            act(vp_decision($rule), (string) $d['ip'], (string) $d['path']);
            return;
        }
    }
}

function filter_rest_virtual_patches(mixed $result): mixed
{
    $d = $GLOBALS['gobuildwp_sp_vp_deferred'] ?? null;
    if (!is_array($d) || is_wp_error($result)) {
        return $result;
    }
    $GLOBALS['gobuildwp_sp_vp_deferred'] = null;
    if (cloud_token_call()) {
        return $result;
    }
    $signed_in = is_user_logged_in();
    $can = static fn(string $cap): bool => current_user_can($cap);
    foreach ($d['rules'] as $rule) {
        if (vp_applies_to($rule, $signed_in, $can)) {
            $decision = vp_decision($rule);
            $event = record_event($decision['type'], 'blocked', (string) $d['ip'], $decision['rule'], (string) $d['path'], $decision['detail']);
            escalate((string) $d['ip']);
            return new WP_Error('gobuildwp_blocked', 'Blocked by website protection. Reference: ' . $event['id'], ['status' => 403]);
        }
    }
    return $result;
}
