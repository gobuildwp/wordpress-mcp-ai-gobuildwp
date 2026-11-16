<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BotAccess;

use WP_Error;
use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The bot access report: who robots.txt lets in, what ai.txt says, which crawlers the access
 * logs show, and whether WordPress itself discourages search engines. Monitor only: nothing here
 * writes robots.txt, a header or a setting.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function report(array $input = []): array
{
    $notes = [];
    $robots = read_txt('robots.txt');
    $txt = $robots['content'];
    $groups = parse_robots($txt);
    $blog_public = (string) get_option('blog_public', '1') !== '0';
    if (!$blog_public) {
        $notes[] = 'Settings > Reading has "Discourage search engines from indexing this site" ticked. WordPress then adds noindex to every page, whatever robots.txt says.';
    }
    if ($robots['source'] === 'none') {
        $notes[] = 'No robots.txt could be read, so every crawler is treated as allowed.';
    }
    $cloudflare = $robots['source'] === 'http' && cloudflare_managed($txt);
    if ($cloudflare) {
        $notes[] = 'The robots.txt crawlers receive carries Cloudflare\'s managed block, added at the Cloudflare edge rather than by WordPress. Change it in the Cloudflare dashboard (AI Crawl Control / Manage robots.txt), not on the site.';
    }

    $seen = ($input['include_logs'] ?? true) === false ? ['checked' => [], 'seen' => [], 'unreadable' => 0] : read_logs();

    $crawlers = [];
    foreach (crawlers() as $crawler) {
        $verdict = $txt === '' ? ['access' => 'allowed', 'group' => '', 'disallow_rules' => 0] : evaluate($groups, $crawler['token']);
        $row = [
            'name' => $crawler['token'],
            'operator' => $crawler['operator'],
            'purpose' => $crawler['purpose'],
            'robots' => $verdict['access'],
            'robots_group' => $verdict['group'],
        ];
        if (isset($seen['seen'][$crawler['token']])) {
            $row['log_hits'] = $seen['seen'][$crawler['token']]['hits'];
            $row['last_seen'] = $seen['seen'][$crawler['token']]['last_seen'];
        }
        $crawlers[] = $row;
        if ($verdict['access'] === 'blocked' && $crawler['purpose'] === 'search') {
            $notes[] = sprintf('robots.txt blocks %s, so the site drops out of %s search.', $crawler['token'], $crawler['operator']);
        }
    }
    if ($seen['checked'] === [] && ($input['include_logs'] ?? true) !== false) {
        $notes[] = 'No web server access log was readable from PHP, so which crawlers actually visit is unknown. A host or Cloudflare dashboard shows that.';
    }

    $ai = read_txt('ai.txt');
    $blocked = array_values(array_map(
        static fn(array $row): string => $row['name'],
        array_filter($crawlers, static fn(array $row): bool => $row['robots'] === 'blocked'),
    ));

    return [
        'search_engines_discouraged' => !$blog_public,
        'robots_txt' => [
            'source' => $robots['source'],
            'url' => home_url('/robots.txt'),
            'bytes' => strlen($txt),
            'groups' => count($groups),
            'cloudflare_managed' => $cloudflare,
            'content' => substr($txt, 0, 4000),
        ],
        'ai_txt' => [
            'found' => $ai['source'] !== 'none',
            'source' => $ai['source'],
            'content' => substr($ai['content'], 0, 2000),
        ],
        'cloudflare' => [
            'checked' => false,
            'reason' => 'This plugin has no Cloudflare API connection. Cloudflare\'s "Block AI bots" and bot-fight settings can refuse crawlers that robots.txt allows; check them in the Cloudflare dashboard.',
        ],
        'blocked' => $blocked,
        'crawlers' => $crawlers,
        'logs' => [
            'checked' => $seen['checked'],
            'unreadable_candidates' => $seen['unreadable'],
        ],
        'notes' => $notes,
    ];
}

/**
 * A root text file as crawlers receive it: over HTTP first (what Cloudflare or a cache layer
 * really serves), the file on disk when the loopback request fails.
 *
 * @return array{source: string, content: string}
 */
function read_txt(string $name): array
{
    $page = Page::fetch(home_url('/' . $name), 10);
    if (!$page instanceof WP_Error && $page['status'] === 200 && !looks_like_html($page['html'])) {
        return ['source' => 'http', 'content' => substr($page['html'], 0, MAX_TXT_BYTES)];
    }
    $file = trailingslashit(ABSPATH) . $name;
    if (is_file($file) && is_readable($file)) {
        $content = file_get_contents($file, false, null, 0, MAX_TXT_BYTES);
        if (is_string($content)) {
            return ['source' => 'file', 'content' => $content];
        }
    }
    if ($name === 'robots.txt') {
        // WordPress's virtual robots.txt, built the way do_robots() builds it, without the
        // header() call do_robots() makes.
        $public = (string) get_option('blog_public', '1') !== '0';
        $output = "User-agent: *\n" . ($public ? "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" : "Disallow: /\n");
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's robots.txt filter, applied the way do_robots() applies it.
        $output = (string) apply_filters('robots_txt', $output, $public);

        return ['source' => 'virtual', 'content' => $output];
    }

    return ['source' => 'none', 'content' => ''];
}

function looks_like_html(string $body): bool
{
    return preg_match('/^\s*(<!doctype|<html|<head|<body)/i', $body) === 1;
}

/**
 * Read the tail of whichever access logs PHP can open, counting known crawlers.
 *
 * @return array{checked: list<string>, seen: array<string, array{hits: int, last_seen: string}>, unreadable: int}
 */
function read_logs(): array
{
    $checked = [];
    $unreadable = 0;
    $seen = [];
    foreach (log_candidates() as $path) {
        if (count($checked) >= MAX_LOGS) {
            break;
        }
        // open_basedir turns a probe outside the allowed tree into a warning; that is a "no".
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (!@is_file($path)) {
            continue;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (!@is_readable($path)) {
            $unreadable++;
            continue;
        }
        $lines = tail_lines($path, LOG_TAIL_BYTES);
        if ($lines === null) {
            $unreadable++;
            continue;
        }
        $checked[] = basename($path);
        foreach (scan_log_lines($lines) as $token => $hit) {
            $seen[$token]['hits'] = ($seen[$token]['hits'] ?? 0) + $hit['hits'];
            $seen[$token]['last_seen'] = max($seen[$token]['last_seen'] ?? '', $hit['last_seen']);
        }
    }

    return ['checked' => $checked, 'seen' => $seen, 'unreadable' => $unreadable];
}

/**
 * Where hosts commonly keep this site's access log. Only fixed paths and the filter's
 * additions are tried; nothing an agent sends chooses a path.
 *
 * @return list<string>
 */
function log_candidates(): array
{
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $host = (string) preg_replace('/[^a-z0-9.-]/', '', $host);
    $root = rtrim((string) ABSPATH, '/\\');
    $paths = [
        dirname($root) . '/logs/access.log',
        dirname($root) . '/logs/access_log',
        dirname($root) . '/access-logs/' . $host,
        '/www/wwwlogs/' . $host . '.log',
        '/var/log/nginx/' . $host . '.access.log',
        '/var/log/nginx/access.log',
        '/var/log/apache2/' . $host . '-access.log',
        '/var/log/apache2/access.log',
        '/var/log/httpd/access_log',
        '/usr/local/lsws/logs/access.log',
    ];
    /** @var mixed $extra */
    $extra = apply_filters(LOG_PATHS_FILTER, []);
    if (is_array($extra)) {
        foreach ($extra as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * The last $bytes of a file as lines, the first (probably cut) line dropped.
 *
 * @return list<string>|null
 */
function tail_lines(string $path, int $bytes): ?array
{
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- a bounded read of a log file outside the uploads tree.
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $size = (int) (fstat($handle)['size'] ?? 0);
        $start = max(0, $size - $bytes);
        fseek($handle, $start);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $chunk = (string) fread($handle, $bytes);
    } finally {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
    }
    $lines = preg_split('/\r\n|\n/', $chunk) ?: [];
    if ($start > 0) {
        array_shift($lines);
    }

    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
}
