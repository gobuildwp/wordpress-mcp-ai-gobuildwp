<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Configuration page: the web apps, as their own route in.
 *
 * ChatGPT, Claude on the web, Perplexity, Le Chat and Manus are not a fourth way
 * of authenticating — each still ends in OAuth or a bearer token. They are a
 * fourth way of *arriving*, and that is what the chooser was getting wrong:
 * someone whose AI is a browser tab does not know whether they want OAuth or an
 * access token, and had to guess a credential before they could find out their
 * app was even supported. Picking the app first, and being told which credential
 * it accepts, is the order the question actually arrives in.
 *
 * Nothing here is a new mechanism. The walkthroughs are the same ones the OAuth
 * and access-token panels render; this file only groups them by app.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The web apps, in the order the chooser lists them.
 *
 * Keyed by the client-registry key where one exists, because the Overview screen
 * labels live connections from that registry and the two lists must not drift.
 *
 * @return array<string, array{label: string, oauth: string, bearer_steps: ?string}>
 */
function wppilot_web_apps(): array
{
    return [
        // `bearer_steps` names the walkthrough to show for the access-token
        // route, or null where the app has no field to put a header in. It is a
        // lookup key, never a credential.
        'claude-web' => [
            'label' => __('Claude (web)', domain: 'wppilot'),
            'oauth' => 'connector',
            'bearer_steps' => 'claude-web',
        ],
        'chatgpt' => ['label' => 'ChatGPT', 'oauth' => 'chatgpt', 'bearer_steps' => null],
        'perplexity' => ['label' => 'Perplexity', 'oauth' => 'perplexity', 'bearer_steps' => 'perplexity'],
        'mistral-lechat' => [
            'label' => 'Mistral Le Chat',
            'oauth' => 'mistral',
            'bearer_steps' => 'mistral-lechat',
        ],
        'manus' => ['label' => 'Manus', 'oauth' => 'manus', 'bearer_steps' => 'manus'],
    ];
}

/**
 * The OAuth walkthrough for one app, flattened to plain strings.
 *
 * The step builders return title/body/copy records for the OAuth panel's own
 * renderer; here they become a numbered list, so the same words are used in both
 * places rather than written twice and drifting.
 *
 * @return list<string>
 */
function wppilot_web_app_oauth_steps(string $which, string $mcp_name, string $mcp_url): array
{
    $steps = match ($which) {
        'chatgpt' => wppilot_oauth_chatgpt_steps($mcp_name, $mcp_url),
        'manus' => wppilot_oauth_manus_steps($mcp_name, $mcp_url),
        'mistral' => wppilot_oauth_mistral_steps($mcp_name, $mcp_url),
        'perplexity' => wppilot_oauth_perplexity_steps($mcp_name, $mcp_url),
        default => wppilot_oauth_connector_steps('Claude', $mcp_name, $mcp_url),
    };

    $flat = [];
    foreach ($steps as $step) {
        $body = trim((string) ($step['body'] ?? ''));
        $copy = trim((string) ($step['copy'] ?? ''));
        $flat[] = $copy !== '' ? $body . ' ' . $copy : $body;
    }

    return $flat;
}

/**
 * Render the web-apps method panel in the chooser: what this route is, and the
 * one condition that decides whether it can work at all.
 */
function wppilot_render_web_apps_step(): void
{
    $reachable = !wppilot_host_unreachable_from_cloud();
    ?>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'For an AI that lives in a browser tab rather than in your editor. Pick the app below and follow its own steps — each one adds this site from its own settings screen, and the page tells you which credential that app accepts.',
            domain: 'wppilot',
        ); ?>
    </p>

    <?php if (!$reachable): ?>
        <div class="notice notice-warning inline" style="margin:0;">
            <p style="margin:0;">
                <strong><?php esc_html_e('None of these can reach this site.', domain: 'wppilot'); ?></strong>
                <?php esc_html_e(
                    'Every web app connects from its own servers, so it needs a URL that resolves on the public internet over HTTPS. This site is on a local address. Use OAuth or an access token with a client that runs on this machine, or put the site somewhere publicly reachable first.',
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>
    <?php else: ?>
        <p class="description" style="margin:0;">
            <?php esc_html_e(
                'All of them connect from their own servers, so this site must stay reachable over public HTTPS while they are used.',
                domain: 'wppilot',
            ); ?>
        </p>
    <?php endif; ?>
    <?php
}

/**
 * Render the per-app walkthroughs.
 *
 * One tab per app; inside, the OAuth route and — where the app can store a fixed
 * Authorization header — the access-token route as an alternative. Apps that
 * cannot take a header say so rather than leaving the reader to wonder whether
 * the section is missing.
 */
function wppilot_render_web_apps_config_section(string $oauth_url, string $token_url, ?string $token): void
{
    $default_name = wppilot_get_mcp_server_name_default();
    $display_token = $token ?? WPPILOT_TOKEN_PLACEHOLDER;
    $token_steps = wppilot_build_token_web_ui_configs($token_url, $display_token);

    $apps = [];
    foreach (wppilot_web_apps() as $key => $app) {
        $steps_key = $app['bearer_steps'];
        $has_bearer = $steps_key !== null && array_key_exists($steps_key, $token_steps);
        $apps[$key] = [
            'label' => $app['label'],
            'oauth' => wppilot_web_app_oauth_steps($app['oauth'], $default_name, $oauth_url),
            'token' => $has_bearer ? $token_steps[$steps_key]['steps'] : [],
            'tokenHint' => $has_bearer ? $token_steps[$steps_key]['hint'] : '',
        ];
    }

    ?>
    <h2 class="wppilot-step-heading" id="wppilot-webapps-steps">
        <span class="wppilot-step-badge">2</span>
        <?php esc_html_e('Connect Your AI Client', domain: 'wppilot'); ?>
    </h2>

    <div class="wppilot-client-tabs" style="gap:8px; margin-top:16px; margin-bottom:0;">
        <?php foreach ($apps as $key => $app): ?>
            <button
                type="button"
                class="wppilot-client-tab wppilot-webapp-tab"
                data-app="<?php echo esc_attr((string) $key); ?>"
                onclick="wppilotSetWebApp('<?php echo esc_js((string) $key); ?>', this)"
            ><?php echo esc_html($app['label']); ?></button>
        <?php endforeach; ?>
    </div>

    <div id="wppilot-webapps-content" style="display:none; margin-top:16px;">
        <?php if (!wppilot_oauth_transport_allowed()): ?>
            <?php // The OAuth routes are not served on a public plain-HTTP site,
                  // so walking someone through an endpoint that 404s would be a
                  // dead end with no explanation. The method card is disabled for
                  // the same condition; say so here rather than disagreeing with it. ?>
            <p class="description" style="margin:0 0 18px;"><?php esc_html_e(
                'Sign in with OAuth needs HTTPS, so it is unavailable on this site. Use the access token below instead.',
                domain: 'wppilot',
            ); ?></p>
        <?php else: ?>
            <p class="wppilot-legend" style="margin:0 0 6px;"><?php esc_html_e(
                'Sign in with OAuth',
                domain: 'wppilot',
            ); ?></p>
            <ol id="wppilot-webapp-oauth-steps" style="margin:0 0 18px 20px; padding:0;"></ol>
        <?php endif; ?>

        <div id="wppilot-webapp-token-wrap">
            <p class="wppilot-legend" style="margin:0 0 6px;"><?php esc_html_e(
                'Or use an access token instead',
                domain: 'wppilot',
            ); ?></p>
            <ol id="wppilot-webapp-token-steps" style="margin:0 0 8px 20px; padding:0;"></ol>
            <p class="description" id="wppilot-webapp-token-hint" style="margin:0 0 8px;"></p>
        </div>

        <p class="description" id="wppilot-webapp-token-none" style="display:none; margin:0;"></p>
    </div>

    <?php
    wppilot_enqueue_admin_script(
        'wppilot-connect-web-apps',
        'connect-web-apps.js',
        data_var: 'wppilotConnectWebApps',
        data: [
            'apps' => $apps,
            'noToken' => __(
                'This app has no field for a fixed Authorization header, so OAuth above is the only way in. That is a limit of the app, not of this site.',
                domain: 'wppilot',
            ),
        ],
    );
}
