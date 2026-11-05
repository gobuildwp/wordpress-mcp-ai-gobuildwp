<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

/*
 * GoBuildWP pre-WordPress firewall (auto_prepend_file). Do not edit: the plugin rewrites this file.
 *
 * This runs before WordPress, every plugin and the theme, from auto_prepend_file. It reads the
 * state the security-protection kit writes beside it (state.php: the block list, the Cloud feed's
 * rules and blocklist, the allowlist and the firewall mode) and refuses the requests the kit
 * itself would refuse, so a block holds while the site is broken. It never touches the database,
 * never throws, and never looks at signed-in users: a request that carries a WordPress login
 * cookie or an Authorization header is left to the kit, which knows who is asking.
 */

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection\Prepend;

if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg' || defined('GOBUILDWP_FIREWALL_RAN')) {
    return;
}
define('GOBUILDWP_FIREWALL_RAN', true);
if (($_SERVER['SCRIPT_FILENAME'] ?? '') !== '' && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit();
}

const ENGINE_VERSION = 1;
const MAX_INSPECT_BYTES = 65536;
const MAX_INSPECT_VALUES = 500;
const MAX_LOG_BYTES = 262144;

function is_ip(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function normalize_ip(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return '';
    }
    if (strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
        return (string) inet_ntop(substr($packed, 12));
    }
    return (string) inet_ntop($packed);
}

function ip_matches(string $ip, string $cidr): bool
{
    if ($ip === '' || $cidr === '') {
        return false;
    }
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$net, $bits] = explode('/', $cidr, 2);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/** @param list<string> $list */
function ip_in_list(string $ip, array $list): bool
{
    foreach ($list as $cidr) {
        if (is_string($cidr) && ip_matches($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/** @param array<string, mixed> $state */
function client_ip(array $state): string
{
    $remote = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? trim($_SERVER['REMOTE_ADDR']) : '';
    if (!is_ip($remote)) {
        return '';
    }
    $remote = normalize_ip($remote);
    $source = (string) ($state['ip_source'] ?? 'remote_addr');
    if ($source === 'cloudflare' && ip_in_list($remote, (array) ($state['cloudflare_ranges'] ?? []))) {
        $cf = is_string($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null) ? trim($_SERVER['HTTP_CF_CONNECTING_IP']) : '';
        if (is_ip($cf)) {
            return normalize_ip($cf);
        }
    } elseif ($source === 'x_forwarded_for') {
        $proxies = (array) ($state['trusted_proxies'] ?? []);
        if ($proxies !== [] && ip_in_list($remote, $proxies)) {
            $xff = is_string($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
            foreach (array_reverse(array_map('trim', explode(',', $xff))) as $part) {
                if (is_ip($part) && !ip_in_list(normalize_ip($part), $proxies)) {
                    return normalize_ip($part);
                }
            }
        }
    }
    return $remote;
}

/**
 * @param array<mixed> $in
 * @param list<string> $out
 */
function flatten(array $in, array &$out, int &$budget, int $depth): void
{
    if ($depth > 5) {
        return;
    }
    foreach ($in as $value) {
        if ($budget <= 0 || count($out) >= MAX_INSPECT_VALUES) {
            return;
        }
        if (is_array($value)) {
            flatten($value, $out, $budget, $depth + 1);
        } elseif (is_scalar($value)) {
            $s = substr((string) $value, 0, $budget);
            if ($s !== '') {
                $out[] = str_contains($s, '%') ? rawurldecode($s) : $s;
                $budget -= strlen($s);
            }
        }
    }
}

/**
 * @return array{path: string, args: list<string>, ua: string, files: list<string>}
 */
function request_parts(): array
{
    $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
    $values = [];
    $budget = MAX_INSPECT_BYTES;
    flatten($_GET, $values, $budget, 0);
    flatten($_POST, $values, $budget, 0);
    $names = [];
    array_walk_recursive($_FILES, static function ($value, $key) use (&$names): void {
        if ($key === 'name' && is_string($value) && count($names) < 50) {
            $names[] = $value;
        }
    });
    return [
        'path' => rawurldecode((string) strtok($uri, '?')),
        'args' => $values,
        'ua' => is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : '',
        'files' => $names,
    ];
}

/**
 * @param list<array{id: string, in: list<string>, pattern: string, label: string}> $rules
 * @param array{path: string, args: list<string>, ua: string, files: list<string>} $parts
 * @return array{id: string, label: string, in: string}|null
 */
function firewall_match(array $rules, array $parts): ?array
{
    foreach ($rules as $rule) {
        if (!is_array($rule) || !is_string($rule['pattern'] ?? null)) {
            continue;
        }
        foreach ((array) ($rule['in'] ?? []) as $where) {
            $haystack = match ($where) {
                'path' => [$parts['path']],
                'args' => $parts['args'],
                'ua' => [$parts['ua']],
                'files' => $parts['files'],
                default => [],
            };
            foreach ($haystack as $value) {
                if ($value !== '' && @preg_match($rule['pattern'], $value) === 1) {
                    return ['id' => (string) ($rule['id'] ?? ''), 'label' => (string) ($rule['label'] ?? ''), 'in' => (string) $where];
                }
            }
        }
    }
    return null;
}

function has_session(): bool
{
    foreach (array_keys($_COOKIE) as $name) {
        if (is_string($name) && str_starts_with($name, 'wordpress_logged_in_')) {
            return true;
        }
    }
    return ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== '' || ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '') !== '' || ($_SERVER['PHP_AUTH_USER'] ?? '') !== '';
}

/**
 * Append one line for the kit to turn into an event on the next WordPress request. The log is
 * capped; once full, blocks still happen but are no longer recorded here.
 *
 * @param array<string, mixed> $event
 */
function log_event(array $event): void
{
    $file = __DIR__ . '/events.log';
    if (is_link($file) || (file_exists($file) && (filesize($file) ?: 0) > MAX_LOG_BYTES)) {
        return;
    }
    $line = json_encode($event, JSON_UNESCAPED_SLASHES);
    if (is_string($line)) {
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

function respond_blocked(string $ref, string $message): void
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Content-Type: text/html; charset=utf-8');
    }
    $text = $message !== '' ? $message : 'This request was blocked by the site\'s website protection.';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Blocked</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
        . '<h1>Access blocked</h1><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p><p>If you think this is a mistake, contact the site owner and give this reference: <code>'
        . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</code></p></body></html>';
    exit();
}

/**
 * @param array<string, mixed> $state
 * @return array{type: string, rule: string, detail: string, message: string}|null
 */
function decide(array $state, string $ip, string $script, int $now): ?array
{
    if ($ip === '' || ip_in_list($ip, (array) ($state['allowlist'] ?? []))) {
        return null;
    }
    $login = $script === 'wp-login.php' || $script === 'xmlrpc.php';
    foreach ((array) ($state['blocks'] ?? []) as $block) {
        if (!is_array($block) || !is_string($block['ip'] ?? null)) {
            continue;
        }
        $expires = $block['expires_at'] ?? null;
        if (is_int($expires) && $expires <= $now) {
            continue;
        }
        $source = (string) ($block['source'] ?? '');
        if ($source === 'login_lockout' && !$login) {
            continue;
        }
        if (ip_matches($ip, $block['ip'])) {
            return $source === 'login_lockout'
                ? ['type' => 'block', 'rule' => $source, 'detail' => 'Too many failed logins', 'message' => 'Too many failed logins from your address. Try again later.']
                : ['type' => 'block', 'rule' => $source, 'detail' => 'Address is blocked', 'message' => ''];
        }
    }
    foreach ((array) ($state['blocklist'] ?? []) as $entry) {
        if (!is_array($entry) || !is_string($entry['ip'] ?? null)) {
            continue;
        }
        $expires = $entry['expires_at'] ?? null;
        if (is_int($expires) && $expires <= $now) {
            continue;
        }
        if (ip_matches($ip, $entry['ip'])) {
            return ['type' => 'block', 'rule' => 'reputation', 'detail' => 'Address is on the reputation blocklist', 'message' => ''];
        }
    }
    if (($state['xmlrpc'] ?? 'on') === 'off' && $script === 'xmlrpc.php') {
        return ['type' => 'firewall', 'rule' => 'xmlrpc_off', 'detail' => 'XML-RPC is turned off', 'message' => ''];
    }
    if (($state['firewall'] ?? 'off') === 'block') {
        $hit = firewall_match((array) ($state['rules'] ?? []), request_parts());
        if ($hit !== null) {
            return ['type' => 'firewall', 'rule' => $hit['id'], 'detail' => $hit['label'] . ' in ' . $hit['in'], 'message' => ''];
        }
    }
    return null;
}

function run(): void
{
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '10000');
    try {
        $state = @include __DIR__ . '/state.php';
        if (!is_array($state) || ($state['enabled'] ?? false) !== true || has_session()) {
            return;
        }
        $now = time();
        $ip = client_ip($state);
        $script = basename(is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '');
        $decision = decide($state, $ip, $script, $now);
        if ($decision === null) {
            return;
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = rawurldecode((string) strtok($uri, '?'));
        $ref = 'pre-' . substr(hash('sha256', $ip . '|' . $now . '|' . $path . '|' . mt_rand()), 0, 10);
        log_event(['id' => $ref, 'at' => $now, 'type' => $decision['type'], 'ip' => $ip, 'rule' => $decision['rule'], 'path' => substr($path, 0, 512), 'detail' => $decision['detail']]);
        respond_blocked($ref, $decision['message']);
    } catch (\Throwable) {
        // A broken state file or an unexpected value must never take the site down.
        return;
    } finally {
        if ($limit !== false) {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }
}

run();
