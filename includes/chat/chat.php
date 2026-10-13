<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: admin workbench and REST API.
 *
 * This file loads the module and wires its hooks. The implementation is split
 * by concern across this directory:
 *
 *   bootstrap.php   constants, feature gate, consent state
 *   admin.php       menu entry, assets, page render
 *   rest.php        route registration and request handlers
 *   session.php     the session record, in memory
 *   storage.php     the $wpdb layer behind sessions
 *   attachments.php image attachments on user messages
 *   models.php      provider and model discovery
 *   tools.php       abilities exposed to the model, and their risk classes
 *   meta-tools.php  the on-demand discovery tools
 *   tool-calls.php  the tool-call record and its approval lifecycle
 *   gutenberg.php   Gutenberg-specific tool result handling
 *   ai.php          building history, generating, and parsing the response
 *   usage.php       token counts per person, and the limits Pro can set
 *
 * bootstrap.php must load first; the rest are order-independent because every
 * symbol in them is a plain function or constant.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/meta-tools.php';
require_once __DIR__ . '/tool-calls.php';
require_once __DIR__ . '/gutenberg.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/usage.php';

// Priority 70 places Chat directly before Visual (80) in the GoBuildWP submenu.
add_action('admin_menu', callback: 'wppilot_register_chat_menu', priority: 70);
add_action('admin_enqueue_scripts', callback: 'wppilot_enqueue_chat_assets');
add_action('rest_api_init', callback: 'wppilot_register_chat_routes');
