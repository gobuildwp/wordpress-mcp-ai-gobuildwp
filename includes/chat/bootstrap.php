<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: shared constants, feature gate, and consent state.
 *
 * Loaded first by chat.php. Everything else in this directory may rely on
 * the constants and predicates defined here.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CHAT_PAGE = 'wppilot-chat';

const WPPILOT_CHAT_REST_NAMESPACE = 'wppilot/v1';

// Base64 inflates size by ~33%, so a single image must stay well under
// WPPILOT_CHAT_MAX_ROW_BYTES (the per-session storage guard); otherwise it would be
// blanked on persist and the model would never receive it. Keep the two in sync.
const WPPILOT_CHAT_MAX_IMAGE_BYTES = 3_145_728;

const WPPILOT_CHAT_MAX_ATTACHMENTS = 4;

const WPPILOT_CHAT_MAX_ROW_BYTES = 5_242_880;

const WPPILOT_CHAT_MAX_SESSIONS_PER_USER = 50;

const WPPILOT_CHAT_CONSENT_META = 'wppilot_chat_consent';

/**
 * Whether GoBuildWP Chat is enabled. Site owners can turn the feature off entirely
 * with `add_filter('wppilot_chat_enabled', '__return_false')`: no menu entry, no
 * assets, no REST routes, and the page itself refuses to render.
 */
function wppilot_chat_is_enabled(): bool
{
    return apply_filters('wppilot_chat_enabled', value: true) !== false;
}

/**
 * Whether the current user has accepted the one-time GoBuildWP Chat cost notice.
 */
function wppilot_chat_user_has_consented(): bool
{
    return get_user_meta(get_current_user_id(), WPPILOT_CHAT_CONSENT_META, single: true) === '1';
}

/**
 * @return array{available: bool, reason: string, message: string}
 */
function wppilot_chat_status(): array
{
    if (!function_exists('wp_ai_client_prompt')) {
        return [
            'available' => false,
            'reason' => 'missing_ai_client',
            'message' => __(
                'GoBuildWP Chat requires WordPress 7 or newer, with an AI provider configured.',
                domain: 'wppilot',
            ),
        ];
    }

    if (!wppilot_chat_native_tools_available()) {
        return [
            'available' => false,
            'reason' => 'missing_native_tool_calling',
            'message' => __(
                'GoBuildWP Chat requires WordPress AI Client native function calling support.',
                domain: 'wppilot',
            ),
        ];
    }

    return [
        'available' => true,
        'reason' => 'available',
        'message' => __('Ready to run GoBuildWP with native tool calls.', domain: 'wppilot'),
    ];
}

function wppilot_chat_url(): string
{
    return add_query_arg(['page' => WPPILOT_CHAT_PAGE], admin_url('admin.php'));
}
