<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Chat token usage, per person, and the limits Pro (or a site's own code) can set on it.
 *
 * Every Chat model call goes through the WordPress AI Client, whose result reports the prompt
 * and completion tokens the provider billed. They are added up per user and day in user meta,
 * so a person can see what their chats cost, and a limit can stop a model call before it is
 * made instead of after the bill. Only the counts are kept, never the prompt or the answer.
 *
 * Free has no limits; `wppilot_chat_usage_limits` lets GoBuildWP Pro (or any code) set a daily
 * and a monthly token limit per user.
 */

const WPPILOT_CHAT_USAGE_META = 'wppilot_chat_usage';

/** Days of history kept per user: this month plus last month, for the monthly total. */
const WPPILOT_CHAT_USAGE_DAYS = 62;

/** Share of a limit at which the meter starts warning. */
const WPPILOT_CHAT_USAGE_WARN_AT = 0.8;

/**
 * Prompt and completion tokens from an AI Client result, or null when the provider gave none.
 *
 * @return array{prompt: int, completion: int}|null
 */
function wppilot_chat_result_tokens(object $result): ?array
{
    if (!method_exists($result, 'getTokenUsage')) {
        return null;
    }
    try {
        $usage = $result->getTokenUsage();
    } catch (Throwable) {
        return null;
    }
    if (!is_object($usage) || !method_exists($usage, 'getPromptTokens') || !method_exists($usage, 'getCompletionTokens')) {
        return null;
    }
    $prompt = max(0, (int) $usage->getPromptTokens());
    $completion = max(0, (int) $usage->getCompletionTokens());

    return $prompt + $completion > 0 ? ['prompt' => $prompt, 'completion' => $completion] : null;
}

/**
 * The stored days for a user, newest first, trimmed to the history window.
 *
 * @return array<string, array{prompt: int, completion: int, calls: int}>
 */
function wppilot_chat_usage_days(int $user_id): array
{
    $stored = get_user_meta($user_id, WPPILOT_CHAT_USAGE_META, true);
    if (!is_array($stored)) {
        return [];
    }
    $days = [];
    foreach ($stored as $day => $row) {
        if (!is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !is_array($row)) {
            continue;
        }
        $days[$day] = ['prompt' => (int) ($row['prompt'] ?? 0), 'completion' => (int) ($row['completion'] ?? 0), 'calls' => (int) ($row['calls'] ?? 0)];
    }
    krsort($days);

    return array_slice($days, 0, WPPILOT_CHAT_USAGE_DAYS, preserve_keys: true);
}

function wppilot_chat_record_usage(int $user_id, int $prompt, int $completion, ?int $now = null): void
{
    if ($user_id <= 0 || $prompt + $completion <= 0) {
        return;
    }
    $day = gmdate('Y-m-d', $now ?? time());

    // Model steps run in parallel (several tabs, several sessions). Without a lock each one reads
    // the same total and the last write wins, so usage the provider billed goes unrecorded. The
    // user's meta was cached when the request authenticated, so it is read fresh under the lock.
    global $wpdb;
    $lock = 'wppilot_chat_usage_' . $user_id;
    $locked = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')
        && (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) === '1';
    if ($locked && function_exists('wp_cache_delete')) {
        wp_cache_delete($user_id, 'user_meta');
    }
    try {
        $days = wppilot_chat_usage_days($user_id);
        $row = $days[$day] ?? ['prompt' => 0, 'completion' => 0, 'calls' => 0];
        $days[$day] = ['prompt' => $row['prompt'] + $prompt, 'completion' => $row['completion'] + $completion, 'calls' => $row['calls'] + 1];
        krsort($days);
        update_user_meta($user_id, WPPILOT_CHAT_USAGE_META, array_slice($days, 0, WPPILOT_CHAT_USAGE_DAYS, preserve_keys: true));
    } finally {
        if ($locked) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}

/**
 * Today's and this month's totals (UTC), and the limits that apply to this user.
 *
 * @return array{today: int, month: int, calls_today: int, limits: array{day: int, month: int}}
 */
function wppilot_chat_usage_summary(int $user_id, ?int $now = null): array
{
    $now ??= time();
    $today = gmdate('Y-m-d', $now);
    $month = gmdate('Y-m', $now);
    $summary = ['today' => 0, 'month' => 0, 'calls_today' => 0];
    foreach (wppilot_chat_usage_days($user_id) as $day => $row) {
        $tokens = $row['prompt'] + $row['completion'];
        if ($day === $today) {
            $summary['today'] += $tokens;
            $summary['calls_today'] += $row['calls'];
        }
        if (str_starts_with($day, $month)) {
            $summary['month'] += $tokens;
        }
    }

    return $summary + ['limits' => wppilot_chat_usage_limits($user_id)];
}

/**
 * Token limits for one user; 0 means none.
 *
 * @return array{day: int, month: int}
 */
function wppilot_chat_usage_limits(int $user_id): array
{
    /**
     * Daily and monthly Chat token limits for a user. Free sets none.
     *
     * @param array{day: int, month: int} $limits
     * @param int                         $user_id
     */
    $limits = apply_filters('wppilot_chat_usage_limits', ['day' => 0, 'month' => 0], $user_id);

    return [
        'day' => is_array($limits) ? max(0, (int) ($limits['day'] ?? 0)) : 0,
        'month' => is_array($limits) ? max(0, (int) ($limits['month'] ?? 0)) : 0,
    ];
}

/**
 * Refuse a model call when the user has reached a limit. Checked before the call, so a limit is
 * never overshot by more than the one call that crossed it.
 */
function wppilot_chat_usage_allows(int $user_id, ?int $now = null): bool|WP_Error
{
    $usage = wppilot_chat_usage_summary($user_id, $now);
    foreach (['day' => 'today', 'month' => 'month'] as $period => $used) {
        $limit = $usage['limits'][$period];
        if ($limit > 0 && $usage[$used] >= $limit) {
            return new WP_Error(
                'wppilot_chat_usage_limit',
                $period === 'day'
                    ? sprintf(__('You have used your Chat allowance for today (%s tokens). It resets at midnight UTC.', domain: 'wppilot'), number_format_i18n($limit))
                    : sprintf(__('You have used your Chat allowance for this month (%s tokens). Ask a site administrator to raise it.', domain: 'wppilot'), number_format_i18n($limit)),
                ['status' => 429, 'usage' => $usage],
            );
        }
    }

    return true;
}

/**
 * The line shown above Chat: what this person used, against any limit.
 */
function wppilot_chat_render_usage_meter(int $user_id): void
{
    $u = wppilot_chat_usage_summary($user_id);
    $part = static function (int $used, int $limit, string $label): string {
        $text = sprintf('%s: %s', $label, number_format_i18n($used));
        if ($limit > 0) {
            $text .= sprintf(' / %s', number_format_i18n($limit));
            if ($used >= $limit) {
                $text .= ' — ' . __('limit reached', domain: 'wppilot');
            } elseif ($used >= $limit * WPPILOT_CHAT_USAGE_WARN_AT) {
                $text .= ' — ' . __('nearly used up', domain: 'wppilot');
            }
        }

        return $text;
    };
    $warn = ($u['limits']['day'] > 0 && $u['today'] >= $u['limits']['day'] * WPPILOT_CHAT_USAGE_WARN_AT)
        || ($u['limits']['month'] > 0 && $u['month'] >= $u['limits']['month'] * WPPILOT_CHAT_USAGE_WARN_AT);
    printf(
        '<p class="wppilot-chat-usage%s" style="margin:8px 0 12px;color:%s;font-size:12.5px">%s · %s · %s</p>',
        $warn ? ' is-warning' : '',
        $warn ? '#8a4b00' : '#646970',
        esc_html__('Chat tokens', domain: 'wppilot'),
        esc_html($part($u['today'], $u['limits']['day'], __('today', domain: 'wppilot'))),
        esc_html($part($u['month'], $u['limits']['month'], __('this month', domain: 'wppilot'))),
    );
}
