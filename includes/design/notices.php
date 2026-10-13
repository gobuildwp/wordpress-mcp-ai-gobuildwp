<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Notices;

use WPPilot\Design\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const RELOAD_NOTICE_TRANSIENT = 'wppilot_design_pending_reload_notice';

const RELOAD_NOTICE_TTL = 60;

function set_pending_reload_notice(): void
{
    set_transient(RELOAD_NOTICE_TRANSIENT, value: '1', expiration: RELOAD_NOTICE_TTL);
}

function render(): void
{
    if (!Admin\current_user_can_manage()) {
        return;
    }

    $inline_key = 'wppilot_design_admin_notice_' . get_current_user_id();
    /** @var array{type?: string, message?: string}|false $inline */
    $inline = get_transient($inline_key);
    if (is_array($inline)) {
        delete_transient($inline_key);
        $type = $inline['type'] ?? 'success';
        $message = $inline['message'] ?? '';
        $class = match ($type) {
            'error' => 'notice-error',
            'warning' => 'notice-warning',
            default => 'notice-success',
        };
        if ($message !== '') {
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
        }
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen instanceof \WP_Screen || $screen->id !== 'wppilot_page_' . Admin\PAGE_SLUG) {
        return;
    }
    if (get_transient(RELOAD_NOTICE_TRANSIENT) === '1') {
        echo
            '<div class="notice notice-info is-dismissible"><p>'
                . esc_html__(
                    'Active design changed. If your AI client is connected, restart the conversation so it reads the new design.',
                    domain: 'wppilot',
                )
                . '</p></div>'
        ;
    }
}
