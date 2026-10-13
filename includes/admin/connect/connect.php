<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Configuration page.
 *
 * Walks the site owner from "AI Abilities are off" to a connected MCP client.
 * The steps are split across this directory:
 *
 *   agent-prompt.php      the "let your AI coder do it" prompt, shared by every method
 *   abilities-toggle.php  step 1 — turning AI Abilities on, and the production warning
 *   passwords.php         the Application Password connection method
 *   oauth-panel.php       the OAuth connection method
 *   tokens-panel.php      the Access token connection method
 *   web-apps.php          the browser-based AI apps, grouped by app rather than credential
 *   client-configs.php    generated config for each supported client
 *   token-configs.php     generated config for the Access token method
 *   stdio-panel.php       the local WP-CLI stdio connection (wp wppilot mcp serve)
 *   cloud-panel.php       the GoBuildWP Cloud connection method (pairing lives in includes/cloud/)
 *   page.php              the top-level render and shared sections
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__) . '/connect-methods.php';

require_once __DIR__ . '/agent-prompt.php';
require_once __DIR__ . '/abilities-toggle.php';
require_once __DIR__ . '/passwords.php';
require_once __DIR__ . '/oauth-panel.php';
require_once __DIR__ . '/tokens-panel.php';
require_once __DIR__ . '/web-apps.php';
require_once __DIR__ . '/client-configs.php';
require_once __DIR__ . '/token-configs.php';
require_once __DIR__ . '/stdio-panel.php';
require_once __DIR__ . '/cloud-panel.php';
require_once __DIR__ . '/page.php';
