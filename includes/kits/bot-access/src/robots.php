<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BotAccess;

if (!defined('ABSPATH')) {
    exit();
}

/** Largest robots.txt or ai.txt read; Google stops at 500 KiB, and nothing sane is bigger. */
const MAX_TXT_BYTES = 512 * 1024;

/** Tail of each access log read for crawler user agents. */
const LOG_TAIL_BYTES = 2 * 1024 * 1024;

/** Access logs read per report. */
const MAX_LOGS = 3;

/** Filter for extra access-log paths a host knows about: list<string> of absolute paths. */
const LOG_PATHS_FILTER = 'wppilot_kit_bot_access_log_paths';

/**
 * Crawlers the report knows, by the product token robots.txt groups name.
 *
 * purpose: search (a search engine's index), ai_search (an AI answer engine's index, cited back
 * to the site), ai_user (fetched live because a person asked an assistant about the page) or
 * ai_training (collected to train models). `match` is the user-agent substring seen in logs.
 *
 * @return list<array{token: string, operator: string, purpose: string, match: string}>
 */
function crawlers(): array
{
    return [
        ['token' => 'Googlebot', 'operator' => 'Google', 'purpose' => 'search', 'match' => 'googlebot'],
        ['token' => 'Bingbot', 'operator' => 'Microsoft', 'purpose' => 'search', 'match' => 'bingbot'],
        ['token' => 'Applebot', 'operator' => 'Apple', 'purpose' => 'search', 'match' => 'applebot'],
        ['token' => 'DuckDuckBot', 'operator' => 'DuckDuckGo', 'purpose' => 'search', 'match' => 'duckduckbot'],
        ['token' => 'GPTBot', 'operator' => 'OpenAI', 'purpose' => 'ai_training', 'match' => 'gptbot'],
        ['token' => 'OAI-SearchBot', 'operator' => 'OpenAI', 'purpose' => 'ai_search', 'match' => 'oai-searchbot'],
        ['token' => 'ChatGPT-User', 'operator' => 'OpenAI', 'purpose' => 'ai_user', 'match' => 'chatgpt-user'],
        ['token' => 'ClaudeBot', 'operator' => 'Anthropic', 'purpose' => 'ai_training', 'match' => 'claudebot'],
        ['token' => 'Claude-SearchBot', 'operator' => 'Anthropic', 'purpose' => 'ai_search', 'match' => 'claude-searchbot'],
        ['token' => 'Claude-User', 'operator' => 'Anthropic', 'purpose' => 'ai_user', 'match' => 'claude-user'],
        ['token' => 'PerplexityBot', 'operator' => 'Perplexity', 'purpose' => 'ai_search', 'match' => 'perplexitybot'],
        ['token' => 'Perplexity-User', 'operator' => 'Perplexity', 'purpose' => 'ai_user', 'match' => 'perplexity-user'],
        ['token' => 'Google-Extended', 'operator' => 'Google', 'purpose' => 'ai_training', 'match' => ''],
        ['token' => 'Applebot-Extended', 'operator' => 'Apple', 'purpose' => 'ai_training', 'match' => ''],
        ['token' => 'Meta-ExternalAgent', 'operator' => 'Meta', 'purpose' => 'ai_training', 'match' => 'meta-externalagent'],
        ['token' => 'Amazonbot', 'operator' => 'Amazon', 'purpose' => 'ai_search', 'match' => 'amazonbot'],
        ['token' => 'CCBot', 'operator' => 'Common Crawl', 'purpose' => 'ai_training', 'match' => 'ccbot'],
        ['token' => 'Bytespider', 'operator' => 'ByteDance', 'purpose' => 'ai_training', 'match' => 'bytespider'],
        ['token' => 'MistralAI-User', 'operator' => 'Mistral', 'purpose' => 'ai_user', 'match' => 'mistralai-user'],
        ['token' => 'DuckAssistBot', 'operator' => 'DuckDuckGo', 'purpose' => 'ai_search', 'match' => 'duckassistbot'],
    ];
}

/**
 * Parse robots.txt into groups, the way RFC 9309 reads it.
 *
 * Consecutive user-agent lines open one group; the rules after them belong to every agent named.
 * Lines are matched case-insensitively, comments dropped, and unknown fields (Sitemap,
 * Crawl-delay, Content-Signal) skipped.
 *
 * @return list<array{agents: list<string>, rules: list<array{type: string, path: string}>}>
 */
function parse_robots(string $txt): array
{
    $groups = [];
    $current = null;
    $last_was_agent = false;
    foreach (preg_split('/\r\n|\r|\n/', $txt) ?: [] as $line) {
        $line = trim((string) preg_replace('/#.*$/', '', $line));
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$field, $value] = array_map('trim', explode(':', $line, 2));
        $field = strtolower($field);
        if ($field === 'user-agent') {
            if (!$last_was_agent || $current === null) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = ['agents' => [], 'rules' => []];
            }
            $current['agents'][] = strtolower($value);
            $last_was_agent = true;
            continue;
        }
        $last_was_agent = false;
        if ($current !== null && ($field === 'allow' || $field === 'disallow')) {
            $current['rules'][] = ['type' => $field, 'path' => $value];
        }
    }
    if ($current !== null) {
        $groups[] = $current;
    }

    return $groups;
}

/**
 * Whether robots.txt lets a crawler fetch the home page, and whether it fences anything off.
 *
 * The crawler obeys the groups naming its token (merged, as RFC 9309 says), or else the `*`
 * group. The home page "/" is decided by the longest matching rule, Allow winning a tie.
 *
 * @param list<array{agents: list<string>, rules: list<array{type: string, path: string}>}> $groups
 * @return array{access: string, group: string, disallow_rules: int}
 *   access: allowed, blocked (the home page is disallowed: in practice the whole site) or partial.
 */
function evaluate(array $groups, string $token): array
{
    $token = strtolower($token);
    $rules = [];
    $group = '';
    foreach ($groups as $candidate) {
        if (in_array($token, $candidate['agents'], true)) {
            $rules = array_merge($rules, $candidate['rules']);
            $group = $token;
        }
    }
    if ($group === '') {
        foreach ($groups as $candidate) {
            if (in_array('*', $candidate['agents'], true)) {
                $rules = array_merge($rules, $candidate['rules']);
                $group = '*';
            }
        }
    }

    $best = null;
    $disallows = 0;
    foreach ($rules as $rule) {
        if ($rule['path'] === '') {
            // An empty Disallow allows everything; an empty Allow says nothing.
            continue;
        }
        if ($rule['type'] === 'disallow') {
            $disallows++;
        }
        if (!path_matches($rule['path'], '/')) {
            continue;
        }
        $length = strlen($rule['path']);
        if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['type'] === 'allow')) {
            $best = ['length' => $length, 'type' => $rule['type']];
        }
    }

    $access = $best !== null && $best['type'] === 'disallow' ? 'blocked' : ($disallows > 0 ? 'partial' : 'allowed');

    return ['access' => $access, 'group' => $group, 'disallow_rules' => $disallows];
}

/**
 * RFC 9309 path matching: `*` is any run of characters, a trailing `$` anchors the end.
 */
function path_matches(string $pattern, string $path): bool
{
    $anchored = str_ends_with($pattern, '$');
    if ($anchored) {
        $pattern = substr($pattern, 0, -1);
    }
    $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';

    return preg_match($regex, $path) === 1;
}

/**
 * Count known crawlers in access-log lines and note when each was last seen.
 *
 * Only the crawler's name, a hit count and the newest timestamp leave this function: addresses,
 * paths and full user-agent strings are never kept.
 *
 * @param iterable<string> $lines
 * @return array<string, array{hits: int, last_seen: string}>
 */
function scan_log_lines(iterable $lines): array
{
    $known = array_filter(crawlers(), static fn(array $crawler): bool => $crawler['match'] !== '');
    $seen = [];
    foreach ($lines as $line) {
        $lower = strtolower((string) $line);
        foreach ($known as $crawler) {
            if (!str_contains($lower, $crawler['match'])) {
                continue;
            }
            $token = $crawler['token'];
            $seen[$token]['hits'] = ($seen[$token]['hits'] ?? 0) + 1;
            // Combined log format: [10/Oct/2026:13:55:36 +0000]. Lines are in time order, so the
            // last one matched is the newest.
            if (preg_match('#\[(\d{2}/[A-Za-z]{3}/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]#', (string) $line, $m) === 1) {
                $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $m[1]);
                if ($time instanceof \DateTimeImmutable) {
                    $seen[$token]['last_seen'] = $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                }
            }
            $seen[$token]['last_seen'] = $seen[$token]['last_seen'] ?? '';
        }
    }

    return $seen;
}

/**
 * Whether a served robots.txt carries Cloudflare's managed block.
 *
 * Cloudflare's "Manage your robots.txt" / "Block AI bots" settings prepend their own group to
 * the site's file at the edge, so the file in WordPress and the file crawlers read differ.
 */
function cloudflare_managed(string $txt): bool
{
    return stripos($txt, 'BEGIN Cloudflare Managed') !== false
        || stripos($txt, 'Cloudflare Managed Content') !== false;
}
