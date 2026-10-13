<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Configuration page: the top-level render and its shared sections.
 *
 * The page is a sequence of steps; each step's implementation lives in a
 * sibling file in this directory.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render the connect / setup dashboard page.
 */
// Inherent: a top-level admin page template that emits each section (notices, chooser, connect
// client, disabled-state manage list) inline; the branches map one-to-one onto template regions.
// @mago-expect lint:cyclomatic-complexity
function wppilot_render_connect_page(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    $mcp_dependency_error = wppilot_get_mcp_dependency_error();
    $toggle_saved = wppilot_handle_toggle_enabled();
    $enabled = wppilot_is_enabled();
    $mcp_ready = $enabled && $mcp_dependency_error === null;

    $password_result = $mcp_ready ? wppilot_handle_create_password() : null;
    $create_error = is_wp_error($password_result) ? $password_result : null;
    $new_password = is_string($password_result) ? $password_result : null;

    $existing_result = $mcp_ready ? wppilot_handle_use_existing_password() : null;
    $existing_error = is_wp_error($existing_result) ? $existing_result : null;
    $existing_password = is_string($existing_result) ? $existing_result : null;

    $token_result = $mcp_ready ? wppilot_handle_create_token() : null;
    $token_error = is_wp_error($token_result) ? $token_result : null;
    $new_token = is_string($token_result) ? $token_result : null;

    $result_message = match ($_GET['wppilot_result'] ?? null) {
        'revoked' => __('Application password revoked.', domain: 'wppilot'),
        'token_revoked' => __('Access token revoked.', domain: 'wppilot'),
        'token_updated' => __('Access token limits saved.', domain: 'wppilot'),
        'token_cloud_managed' => __('That token is managed by GoBuildWP Cloud. To change what Cloud may do, disconnect and connect the site again.', domain: 'wppilot'),
        'token_update_failed' => __('The access token limits were not saved. A restricted token needs at least one ability or category.', domain: 'wppilot'),
        default => null,
    };

    $copied_label = __('Copied!', domain: 'wppilot');

    ?>
    <?php // Styles for this screen live in includes/assets/admin.css. ?>

    <?php wppilot_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php echo esc_html(wppilot_nav_label(WPPILOT_SETUP_PAGE)); ?></h1>
        <p class="wppilot-lede"><?php esc_html_e(
            'Pick how the client signs in, paste one line into it, and check that it arrived. Credentials, endpoints and every client that has connected are below.',
            domain: 'wppilot',
        ); ?></p>

        <?php /*
         * The setup comes first, the readout second.
         *
         * This screen was ordered state-then-action: five stat panels, the client
         * list, the licence and the ability inventory, and only then the steps
         * that connect something. That is the right order for someone checking on
         * a site they already set up, and the wrong one for everybody else —
         * arriving with nothing connected meant scrolling past every panel to
         * reach the only thing that could change that, and the panels are most
         * informative once a client has actually connected.
         */ ?>
        <?php wppilot_render_mcp_dependency_inline_notice($mcp_dependency_error); ?>

        <?php wppilot_render_authorization_header_warning(); ?>

        <?php if ($toggle_saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php

            esc_html_e('Settings saved.', domain: 'wppilot');
            ?></p></div>
        <?php endif; ?>

        <?php wppilot_render_production_warning(); ?>

        <?php wppilot_render_enable_prompt($mcp_dependency_error); ?>

        <?php if (!$mcp_ready): ?>
            <?php /* Nothing can be connected yet, so the switch is the only thing worth showing. */ ?>
            <div class="wppilot-connect-section">
                <?php wppilot_render_enable_toggle(); ?>
            </div>
        <?php endif; ?>

        <?php if ($mcp_ready): ?>
            <?php if ($create_error !== null): ?>
                <div class="notice notice-error"><p><?php

                echo esc_html($create_error->get_error_message());
                ?></p></div>
            <?php endif; ?>

            <?php if ($result_message !== null): ?>
                <div class="notice notice-success is-dismissible"><p><?php

                echo esc_html($result_message);
                ?></p></div>
            <?php endif; ?>

            <?php /*
             * Authentication comes first. Choosing a method, and generating an
             * application password, is what a user actually came here to do —
             * and since abilities are switched on at activation, the toggle
             * below is normally already correct and only needs finding when
             * someone wants to turn the endpoint off.
             */ ?>
            <div class="wppilot-connect-section">
                <?php wppilot_render_method_chooser(
                    $new_password,
                    $existing_password,
                    $existing_error,
                    $new_token,
                    $token_error,
                ); ?>
            </div>

            <div class="wppilot-connect-section" id="wppilot-step3"<?php echo
                $new_password !== null || $existing_password !== null || $new_token !== null ? '' : ' hidden'
            ; ?>>
                <?php wppilot_render_connect_client_section(
                    $new_password,
                    $existing_password,
                    $existing_error,
                    $new_token,
                    $token_error,
                ); ?>
            </div>

            <div class="wppilot-connect-section">
                <?php wppilot_render_verify_step(); ?>
            </div>

            <div class="wppilot-connect-section">
                <?php wppilot_render_stdio_section(); ?>
            </div>
        <?php endif; ?>

        <?php /*
         * The off switch and the safety profile used to close this page as a
         * fourth step, numbered after the three that connect a client. They are
         * settings for a connection that already exists, and they live on the
         * Settings screen, which the header's status pill links to from here.
         */ ?>
        <?php wppilot_render_dashboard_sections(); ?>

        <?php if (!$mcp_ready && wppilot_get_mcp_passwords() !== []): ?>
            <?php wppilot_render_manage_passwords_section(context: 'disabled'); ?>
        <?php endif; ?>

    </div>

    <script>
    // navigator.clipboard exists only in a secure context (HTTPS, or http://localhost). On a local
    // site served over plain HTTP on a non-localhost host it is undefined, so fall back to a hidden
    // textarea + execCommand('copy'), which needs no secure context.
    window.wppilotClipboardCopy = function (text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            document.body.removeChild(ta);
            ok ? resolve() : reject(new Error('copy command was rejected'));
        });
    };
    function wppilotCopy(id, btn) {
        var text = document.getElementById(id).textContent;
        window.wppilotClipboardCopy(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = '<?php echo esc_js($copied_label); ?>';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        });
    }
    function wppilotTogglePasswordName(btn) {
        var field = document.getElementById('wppilot-password-name-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('wppilot-password-name');
            if (input) { input.focus(); }
        }
    }
    function wppilotToggleUseExisting(btn) {
        var field = document.getElementById('wppilot-use-existing-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('wppilot-existing-password');
            if (input) { input.focus(); }
        }
    }
    </script>
    <?php
}

/**
 * Three-card chooser between OAuth, Application password, and Access token. Security-first:
 * OAuth is the recommended card everywhere except a local site served over self-signed HTTPS,
 * where the browser sign-in would hit an unverifiable certificate; there the password flow (no
 * browser step) is recommended instead. Every panel is rendered; JS shows one at a time
 * (defaulting to the recommended one) and degrades to all visible without JS.
 *
 * The access token is never the recommended card. It is the most powerful credential of the
 * three — long-lived, and carried in a header a client will happily write to a config file — so
 * it is offered as the answer to a specific problem (a caller that cannot run a browser sign-in)
 * rather than presented as the easy default.
 */
// Inherent: which of five cards opens first depends on what this request did; each branch is one card.
// @mago-expect lint:cyclomatic-complexity
function wppilot_render_method_chooser(
    ?string $new_password,
    ?string $existing_password = null,
    ?WP_Error $existing_error = null,
    ?string $new_token = null,
    ?WP_Error $token_error = null,
): void {
    // Security-first: recommend OAuth (no secret in the config, mcp scope, revocable) in
    // every case except a local site on self-signed HTTPS, where the browser cannot verify
    // the certificate during sign-in; there the password flow (no browser step) is smoother.
    // OAuth is only offered where its transport is safe (HTTPS, or a local site). On a public
    // HTTP site it is not selectable; WordPress already blocks application passwords there too.
    $oauth_available = wppilot_oauth_transport_allowed();
    $oauth_recommended =
        $oauth_available && !(wppilot_host_unreachable_from_cloud() && wppilot_likely_self_signed_https());
    // App password carries the recommendation only in the local self-signed case (OAuth available,
    // but the browser cannot verify the cert). On a public HTTP site nothing is recommended.
    $password_recommended = $oauth_available && !$oauth_recommended;
    $token_active = wppilot_token_method_preselected($new_token, $token_error);
    // A token action this request wins the initial panel; otherwise the password
    // rules are unchanged from before the third method existed.
    $password_active =
        !$token_active && wppilot_password_method_preselected($new_password, $existing_password, $existing_error);
    $has_password = $new_password !== null || $existing_password !== null;
    // A GoBuildWP Cloud pairing step that just redirected back here opens its own card.
    $cloud_available = wppilot_cloud_available();
    $cloud_active = $cloud_available && !$token_active && !$password_active && wppilot_cloud_method_preselected();
    // What the page opens on: whatever happened this request, else the
    // recommended method. The script downgrades a password default to something
    // renderable when no password exists yet.
    $default_method = 'oauth';
    if ($token_active) {
        $default_method = 'token';
    } elseif ($cloud_active) {
        $default_method = 'cloud';
    } elseif ($password_active || !$oauth_available) {
        $default_method = 'password';
    }
    ?>
    <h2 class="wppilot-step-heading">
        <span class="wppilot-step-badge">1</span>
        <?php esc_html_e('Choose your authentication method', domain: 'wppilot'); ?>
    </h2>

    <?php wppilot_render_method_cards(
        $oauth_available,
        $oauth_recommended,
        $password_recommended,
        $password_active,
        $token_active,
        $cloud_available,
        $cloud_active,
    ); ?>

    <div class="wppilot-method-panel" data-panel="oauth" hidden>
        <?php wppilot_render_oauth_panel(); ?>
    </div>
    <div class="wppilot-method-panel" data-panel="password"<?php echo $password_active ? '' : ' hidden'; ?>>
        <?php wppilot_render_password_step($new_password, $existing_password, $existing_error); ?>
        <?php wppilot_render_manage_passwords_section(); ?>
    </div>
    <div class="wppilot-method-panel" data-panel="token"<?php echo $token_active ? '' : ' hidden'; ?>>
        <?php wppilot_render_token_step($new_token, $token_error); ?>
        <?php wppilot_render_manage_tokens_section(); ?>
    </div>
    <div class="wppilot-method-panel" data-panel="webapps" hidden>
        <?php wppilot_render_web_apps_step(); ?>
    </div>
    <?php if ($cloud_available): ?>
    <div class="wppilot-method-panel" data-panel="cloud"<?php echo $cloud_active ? '' : ' hidden'; ?>>
        <?php wppilot_render_cloud_panel(); ?>
    </div>
    <?php endif; ?>

    <noscript>
        <style>.wppilot-method-panel[hidden], #wppilot-step3[hidden] { display: block; }</style>
    </noscript>

    <script>
    (function () {
        var hasPassword = <?php echo $has_password ? 'true' : 'false'; ?>;
        var oauthAvailable = <?php echo $oauth_available ? 'true' : 'false'; ?>;
        var defaultMethod = <?php echo wppilot_script_json($default_method); ?>;
        // Re-query on every click so panels rendered in later containers (the step 3 section) are
        // toggled too. Step 3 opens for OAuth and for the access token immediately — both render a
        // usable snippet with a labelled placeholder — and for the password method only once a
        // password exists (otherwise the whole step 3 section stays hidden).
        function apply(method) {
            document.querySelectorAll('.wppilot-method-card').forEach(function (c) {
                c.classList.toggle('is-active', c.getAttribute('data-method') === method);
            });
            document.querySelectorAll('.wppilot-method-panel').forEach(function (p) {
                p.hidden = p.getAttribute('data-panel') !== method;
            });
            var step3 = document.getElementById('wppilot-step3');
            var visible = method === 'oauth'
                || method === 'token'
                || method === 'webapps'
                || (method === 'password' && hasPassword);
            if (step3) { step3.hidden = !visible; }
        }
        document.querySelectorAll('.wppilot-method-card').forEach(function (card) {
            card.addEventListener('click', function () { apply(card.getAttribute('data-method')); });
        });

        // Open on a method rather than on nothing. With no selection the whole of
        // step 2 stayed hidden, so the page read 1, 3, 4 — a numbered sequence
        // with a hole in it, which looks like a bug and hides the part that
        // actually connects a client. The application-password panel has nothing
        // to show until a password exists, so it is not used as the opening
        // choice in that state.
        // Deferred to DOM ready on purpose. This script is inline and runs while
        // the document is still parsing, and the section it has to reveal —
        // step 2 — is further down the page. Running now would call
        // getElementById on an element the parser has not reached, silently do
        // nothing, and leave the page reading 1, 3, 4 exactly as before.
        function selectInitialMethod() {
            var initial = defaultMethod;
            if (initial === 'password' && !hasPassword) {
                initial = oauthAvailable ? 'oauth' : 'token';
            }
            apply(initial);

            // The Overview links here with a method already chosen. Without this
            // the link lands on whichever panel the server picked, which for a
            // visitor who clicked "Create an access token" is the wrong one.
            var requested = (window.location.hash || '').replace('#wppilot-', '').replace('-method', '');
                if (['oauth', 'password', 'token', 'webapps', 'cloud'].indexOf(requested) !== -1) {
                var card = document.querySelector('.wppilot-method-card[data-method="' + requested + '"]');
                if (card && !card.disabled) {
                    apply(requested);
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', selectInitialMethod);
        } else {
            selectInitialMethod();
        }
    })();
    </script>

    <?php if ($new_token !== null): ?>
    <script>
    (function () {
        // Creating a token posts the page back, and the browser restores the top
        // of the document — but the token is revealed several screens down, shown
        // once, and never recoverable. Landing above it is how someone loses the
        // only copy. Focus as well as scroll, so a screen reader lands there too.
        var box = document.getElementById('wppilot-token-created');
        if (!box) { return; }
        box.setAttribute('tabindex', '-1');
        window.requestAnimationFrame(function () {
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            box.focus({ preventScroll: true });
        });
    }());
    </script>
    <?php endif; ?>
    <?php
}

/**
 * The method cards.
 *
 * Split from the chooser because it holds all the branching — an availability
 * test, two recommendation badges, and three active states — while the chooser
 * itself is about which panel is open. Together they were one function deciding
 * two unrelated things.
 */
// One flag per card state, computed once by the chooser and only rendered here.
// @mago-expect lint:excessive-parameter-list
function wppilot_render_method_cards(
    bool $oauth_available,
    bool $oauth_recommended,
    bool $password_recommended,
    bool $password_active,
    bool $token_active,
    bool $cloud_available = false,
    bool $cloud_active = false,
): void {
    $badge_label = $oauth_recommended
        ? esc_html__('Recommended for your setup', domain: 'wppilot')
        : esc_html__('Recommended for your local setup', domain: 'wppilot');
    $badge = '<span class="wppilot-recommended-badge">' . $badge_label . '</span>';
    ?>
    <div class="wppilot-method-cards">
        <?php if ($oauth_available): ?>
        <button
            type="button"
            class="wppilot-method-card"
            data-method="oauth"
        >
            <span class="wppilot-method-title">
                <?php esc_html_e('OAuth', domain: 'wppilot'); ?>
                <?php echo $oauth_recommended ? $badge : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </span>
            <span class="description"><?php esc_html_e(
                'Sign in through the browser, no password to copy.',
                domain: 'wppilot',
            ); ?></span>
        </button>
        <?php endif; ?>
        <?php if (!$oauth_available): ?>
        <button
            type="button"
            class="wppilot-method-card"
            disabled
            aria-disabled="true"
            style="opacity:.55; cursor:not-allowed;"
        >
            <span class="wppilot-method-title">
                <?php esc_html_e('OAuth', domain: 'wppilot'); ?>
                <span
                    class="wppilot-recommended-badge"
                    style="color:#8a6d1a; background:#fcf3d7;"
                ><?php esc_html_e('Requires HTTPS', domain: 'wppilot'); ?></span>
            </span>
            <span class="description"><?php esc_html_e(
                'OAuth sends tokens over the network, so it needs HTTPS. Enable HTTPS on your site to use it.',
                domain: 'wppilot',
            ); ?></span>
        </button>
        <?php endif; ?>
        <button
            type="button"
            class="wppilot-method-card<?php echo $password_active ? ' is-active' : ''; ?>"
            data-method="password"
        >
            <span class="wppilot-method-title">
                <?php esc_html_e('Application password', domain: 'wppilot'); ?>
                <?php echo $password_recommended ? $badge : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </span>
            <span class="description"><?php esc_html_e(
                'Generate a password and paste it into the client config.',
                domain: 'wppilot',
            ); ?></span>
        </button>
        <?php
        // Tokens are authenticated by the OAuth middleware, which only loads
        // over HTTPS (or on a local environment). Offering the card elsewhere
        // minted tokens that every call then refused with a 401.
        if (wppilot_oauth_transport_allowed()) : ?>
        <button
            type="button"
            class="wppilot-method-card<?php echo $token_active ? ' is-active' : ''; ?>"
            data-method="token"
        >
            <span class="wppilot-method-title">
                <?php esc_html_e('Access token', domain: 'wppilot'); ?>
            </span>
            <span class="description"><?php esc_html_e(
                'A bearer token for callers with no browser: the Claude and OpenAI APIs, scripts, cron.',
                domain: 'wppilot',
            ); ?></span>
        </button>
        <?php endif; ?>
        <?php /*
         * Not a fourth credential — a fourth way of arriving. Someone whose AI is
         * a browser tab does not know yet whether they want OAuth or a token, and
         * should not have to guess one before finding out their app is supported.
         */ ?>
        <button
            type="button"
            class="wppilot-method-card"
            data-method="webapps"
        >
            <span class="wppilot-method-title">
                <?php esc_html_e('Web apps', domain: 'wppilot'); ?>
            </span>
            <span class="description"><?php esc_html_e(
                'ChatGPT, Claude, Perplexity, Le Chat, Manus — pick the app and follow its own steps.',
                domain: 'wppilot',
            ); ?></span>
        </button>
        <?php /*
         * Offered only where an access token can authenticate at all (HTTPS or a
         * local site), since what the Cloud is given is an access token.
         */ ?>
        <?php if ($cloud_available): ?>
        <button
            type="button"
            class="wppilot-method-card<?php echo $cloud_active ? ' is-active' : ''; ?>"
            data-method="cloud"
        >
            <span class="wppilot-method-title">
                <?php esc_html_e('GoBuildWP Cloud', domain: 'wppilot'); ?>
            </span>
            <span class="description"><?php esc_html_e(
                'Pair this site with your GoBuildWP Cloud workspace once; its AI clients connect through it.',
                domain: 'wppilot',
            ); ?></span>
        </button>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the "Connect Your AI Client" container (Step 3), with one method panel toggled by the
 * step 2 chooser. The OAuth panel is always populated; the app-password panel shows the config only
 * once a password exists. The wrapping section stays hidden until a method is picked (and, for app
 * password, until the password is generated), gated by its id from the chooser script.
 */
function wppilot_render_connect_client_section(
    ?string $new_password,
    ?string $existing_password,
    ?WP_Error $existing_error,
    ?string $new_token = null,
    ?WP_Error $token_error = null,
): void {
    $token_active = wppilot_token_method_preselected($new_token, $token_error);
    $password_active =
        !$token_active && wppilot_password_method_preselected($new_password, $existing_password, $existing_error);
    $has_password = $new_password !== null || $existing_password !== null;
    $rest_url = rest_url('mcp/wppilot');
    // OAuth lives on its own MCP server so the canonical route above stays Application-Password-only
    // and untouched by the OAuth challenge. See wppilot_register_oauth_mcp_server().
    $oauth_rest_url = rest_url('mcp/wppilot-oauth');
    $username = wp_get_current_user()->user_login;
    $display_password = $new_password ?? $existing_password ?? 'YOUR-APP-PASSWORD';
    ?>
    <div class="wppilot-method-panel" data-panel="oauth" hidden>
        <?php wppilot_render_oauth_config_section($oauth_rest_url); ?>
    </div>
    <div class="wppilot-method-panel" data-panel="password"<?php echo $password_active ? '' : ' hidden'; ?>>
        <?php if ($has_password): ?>
            <?php wppilot_render_config_section($rest_url, $username, $display_password); ?>
        <?php endif; ?>
    </div>
    <?php /* The token reaches the canonical endpoint — the same URL the application-password
     * snippets use. See WPPilot\OAuth\Middleware\is_any_mcp_route(). */ ?>
    <div class="wppilot-method-panel" data-panel="token"<?php echo $token_active ? '' : ' hidden'; ?>>
        <?php wppilot_render_token_config_section($rest_url, $new_token); ?>
    </div>
    <div class="wppilot-method-panel" data-panel="webapps" hidden>
        <?php wppilot_render_web_apps_config_section($oauth_rest_url, $rest_url, $new_token); ?>
    </div>
    <?php
}

/**
 * Render the tabbed MCP client config section.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 */
function wppilot_render_config_section(string $rest_url, string $username, string $display_password): void
{
    $default_name = wppilot_get_mcp_server_name_default();
    $name_placeholder = '__WPPILOT_MCP_NAME__';
    $pw_slot = '__WPPILOT_PW_SLOT__';
    $password_is_placeholder = hash_equals('YOUR-APP-PASSWORD', $display_password);
    $configs = wppilot_build_configs($rest_url, $username, $display_password, $name_placeholder);
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wppilot_script_json() hex-escapes for script context.
    $configs_json = (string) wppilot_script_json($configs);

    // Taken from the client registry rather than restated here, so adding a
    // client in one place reaches the Overview screen and this list together.
    // Filtered by what actually has a snippet: Claude on the web is in the
    // registry but has no application-password form, and must not offer a tab
    // that would render empty.
    $clients = [];
    foreach (wppilot_selectable_clients() as $key => $client) {
        if (array_key_exists((string) $key, $configs)) {
            $clients[(string) $key] = (string) ($client['label'] ?? $key);
        }
    }

    $copied_label = __('Copied!', domain: 'wppilot');
    $paste_paragraph_initial = wppilot_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $default_name,
    );
    $paste_paragraph_template = wppilot_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $name_placeholder,
        $pw_slot,
    );
    ?>
    <h2 class="wppilot-step-heading">
        <span class="wppilot-step-badge">2</span>
        <?php esc_html_e('Connect your AI client', domain: 'wppilot'); ?>
    </h2>

    <div class="wppilot-client-tabs" style="gap:8px; margin-top:16px; margin-bottom:0;">
    <?php foreach ($clients as $key => $label): ?>
        <button
            type="button"
            class="wppilot-client-tab wppilot-top-client-tab"
            onclick="wppilotSetClient('<?php echo esc_js($key); ?>', this)"
        ><?php echo esc_html($label); ?></button>
    <?php endforeach; ?>
    </div>

    <div id="wppilot-connect-content" style="display:none; margin-top:16px;">

    <?php wppilot_render_local_https_notice(); ?>

    <?php if ($password_is_placeholder) { ?>
        <?php

        // Said loudly, because the alternative is the failure this page used to
        // produce: the snippets below still read as finished commands, someone
        // pastes one with YOUR-APP-PASSWORD still in it, the client reports
        // "connected" because the process started, and no tool ever works. The
        // config is not usable until a real password replaces the placeholder,
        // and the screen has to say so rather than let the copy button imply
        // otherwise.
        ?>
        <div class="notice notice-warning inline" style="margin:0 0 12px;">
            <p style="margin:0 0 6px;">
                <strong><?php esc_html_e('These snippets are not ready to use yet.', domain: 'wppilot'); ?></strong>
                <?php esc_html_e(
                    'They contain the placeholder YOUR-APP-PASSWORD because no application password has been created. Copied as they are, your client will appear to connect and then fail every call, which is a hard fault to diagnose.',
                    domain: 'wppilot',
                ); ?>
            </p>
            <p style="margin:0;"><?php esc_html_e(
                'Create an application password above; every snippet on this page then fills itself in.',
                domain: 'wppilot',
            ); ?></p>
        </div>
    <?php } ?>

    <?php if (!$password_is_placeholder) {
        wppilot_render_mcpb_download($display_password, $default_name);
    } ?>

    <?php wppilot_render_prompt_password_notice(); ?>

    <div class="wppilot-paste-block" id="wppilot-paste-block" style="display:none;">
        <div class="wppilot-paste-content" id="wppilot-paste-content">
            <pre id="wppilot-paste-text"><?php echo esc_html($paste_paragraph_initial); ?></pre>
        </div>
        <div class="wppilot-paste-actions">
            <button
                type="button"
                class="button-link"
                id="wppilot-paste-expand"
                onclick="wppilotToggleExpandPaste(this)"
                aria-expanded="false"
                aria-controls="wppilot-paste-content"
            ><?php esc_html_e('Show full text', domain: 'wppilot'); ?></button>
            <button
                type="button"
                class="button button-primary"
                onclick="wppilotCopyPaste(this)"
            ><?php esc_html_e('Copy prompt', domain: 'wppilot'); ?></button>
            <p
                id="wppilot-paste-copied-warning"
                style="display:none; margin:0; color:#d63638; font-size:13px; font-weight:600;"
            >
                <?php esc_html_e(
                    "Don't share with anyone: it contains an application password that grants access to this WordPress site.",
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>
    </div>

    <p style="margin:6px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="wppilot-server-name-toggle"
            aria-expanded="false"
            aria-controls="wppilot-server-name-field"
            onclick="wppilotToggleServerName(this)"
        ><?php esc_html_e('Change server name (optional)', domain: 'wppilot'); ?></button>
    </p>
    <div id="wppilot-server-name-field" hidden style="display:none; margin: 6px 0 14px;">
        <input
            type="text"
            id="wppilot-mcp-name"
            value="<?php echo esc_attr($default_name); ?>"
            placeholder="<?php echo esc_attr($default_name); ?>"
            maxlength="25"
            style="width:220px;"
            oninput="wppilotUpdateName(this.value)"
        >
        <p class="description" style="margin:6px 0 0;">
            <?php esc_html_e(
                'Give the server a name you’ll recognize. The connection text and snippets below update as you type.',
                domain: 'wppilot',
            ); ?>
        </p>
        <div id="wppilot-name-warning" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Maximum 25 characters reached. Required for client compatibility.',
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>
        <div id="wppilot-name-suggestion" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Tip: keep "gobuildwp" in the name so you (and your AI agent) can tell this MCP server apart from others.',
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>
    </div>

    <div id="wppilot-manual-btn-wrap" style="display:none;">
        <hr style="border:none; border-top:1px solid #dcdcde; margin:12px 0 8px;">
        <button
            type="button"
            class="button button-secondary"
            id="wppilot-manual-toggle"
            aria-expanded="false"
            aria-controls="wppilot-manual-config"
            onclick="wppilotToggleManualConfig(this)"
        ><?php esc_html_e('Manual configuration for your AI client', domain: 'wppilot'); ?></button>
    </div>

    <div id="wppilot-manual-config" hidden style="display:none; margin-top:14px;">
        <?php wppilot_render_json_config_block(); ?>
        <?php wppilot_render_agent_prompt_block('wppilot-password', method: 'password'); ?>
        <p style="margin:10px 0 4px;">
            <button
                type="button"
                class="button-link"
                id="wppilot-npxless-toggle"
                aria-expanded="false"
                aria-controls="wppilot-npxless-config"
                onclick="wppilotToggleNpxlessConfig(this)"
            ><?php esc_html_e(
                'Configs above not working? Try this npx-free alternative.',
                domain: 'wppilot',
            ); ?></button>
        </p>
    </div>

    <div id="wppilot-npxless-config" hidden style="display:none;">
        <p class="description" style="margin:0 0 12px;">
            <?php esc_html_e(
                'Copy this configuration snippet to connect using direct HTTP (no Node/npx required).',
                domain: 'wppilot',
            ); ?>
        </p>

        <div class="wppilot-client-tabs">
            <button
                type="button"
                class="wppilot-client-tab wppilot-npxless-client-tab active"
                onclick="wppilotSetNpxlessClient('claude', this)"
            ><?php esc_html_e('Claude Code', domain: 'wppilot'); ?></button>
            <button
                type="button"
                class="wppilot-client-tab wppilot-npxless-client-tab"
                onclick="wppilotSetNpxlessClient('codex', this)"
            ><?php esc_html_e('Codex', domain: 'wppilot'); ?></button>
        </div>

        <div class="wppilot-tab-content" style="border-radius:4px;">
            <div class="wppilot-config-block">
                <pre id="wppilot-npxless-code"></pre>
                <button type="button" class="button wppilot-copy-btn" onclick="wppilotCopyNpxlessConfig(this)"><?php esc_html_e(
                    'Copy',
                    domain: 'wppilot',
                ); ?></button>
            </div>
            <div id="wppilot-npxless-footer" style="font-size:13px; color:#666; border-top: 1px solid #c3c4c7;">
                <div id="wppilot-npxless-hint" style="padding: 10px 16px;">
                    <?php esc_html_e('Add to your project’s .mcp.json file.', domain: 'wppilot'); ?>
                </div>
                <div id="wppilot-npxless-paths" style="padding: 0 16px 10px;"></div>
            </div>
        </div>
    </div>

    </div><!-- #wppilot-connect-content -->

    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value emitted in this block goes through wppilot_script_json(), which hex-escapes <, >, & and quotes for <script> context. Plugin Check cannot recognise a project-local escaper. ?>
    <script>
    (function () {
        var configs = <?php

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wppilot_script_json() hex-escapes for script context.
        echo $configs_json; ?>;
        var clientLabels = <?php

        echo wppilot_script_json($clients); ?>;
        var client = '';
        var defaultName = <?php

        echo wppilot_script_json($default_name); ?>;
        var pasteTemplate = <?php

        echo wppilot_script_json($paste_paragraph_template); ?>;
        var mcpName = <?php

        echo wppilot_script_json($default_name); ?>;
        var npxlessClient = 'claude';
        var namePlaceholder = <?php

        echo wppilot_script_json($name_placeholder); ?>;
        var passwordSentinel = <?php

        echo wppilot_script_json($pw_slot); ?>;
        var passwordValue = <?php

        echo wppilot_script_json($display_password); ?>;
        var passwordIsPlaceholder = <?php

        echo wppilot_script_json($password_is_placeholder); ?>;
        var usernameValue = <?php

        echo wppilot_script_json($username); ?>;
        var passwordEndpointUrl = <?php

        echo wppilot_script_json($rest_url); ?>;
        var passwordAuthLine = <?php

        echo wppilot_script_json(wppilot_agent_prompt_auth_line('password')); ?>;
        var passwordNotes = <?php

        echo wppilot_script_json(wppilot_agent_prompt_password_notes()); ?>;

        function renderPaste() {
            var text = pasteTemplate.split(namePlaceholder).join(mcpName);
            var container = document.getElementById('wppilot-paste-text');
            container.textContent = '';
            var idx = text.indexOf(passwordSentinel);
            if (idx === -1) {
                container.appendChild(document.createTextNode(text));
                return;
            }
            container.appendChild(document.createTextNode(text.substring(0, idx)));
            if (passwordIsPlaceholder) {
                var span = document.createElement('span');
                span.className = 'wppilot-placeholder';
                span.textContent = 'YOUR-APP-PASSWORD';
                container.appendChild(span);
            } else {
                container.appendChild(document.createTextNode(passwordValue));
            }
            container.appendChild(document.createTextNode(text.substring(idx + passwordSentinel.length)));
        }

        function render() {
            renderConfig();
            renderPaste();
            renderNpxlessConfig();
        }

        function renderConfig() {
            if (!client) { return; }
            var cfg = configs[client];
            if (!cfg) { return; }

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('wppilot-config-code');
            codeEl.textContent = code;
            if (code.indexOf('YOUR-APP-PASSWORD') !== -1) {
                codeEl.innerHTML = codeEl.innerHTML.replace(
                    /YOUR-APP-PASSWORD/g,
                    '<span class="wppilot-placeholder">YOUR-APP-PASSWORD</span>'
                );
            }
            document.getElementById('wppilot-config-hint').innerHTML = cfg.hint;

            // Built from the same cfg the snippet above came from, so the prompt
            // can never describe a client other than the selected tab.
            window.wppilotAgentPrompt('wppilot-password', {
                clientLabel: clientLabels[client] || client,
                serverName: mcpName,
                url: passwordEndpointUrl,
                authLine: passwordAuthLine,
                code: code,
                paths: cfg.paths,
                isShell: cfg.isShell,
                hasSecret: true,
                notes: passwordNotes
            });

            var mergeNote = document.getElementById('wppilot-config-merge-note');
            if (mergeNote) { mergeNote.style.display = cfg.isShell ? 'none' : ''; }

            var isDesktop = client === 'claude-desktop';
            var mcpbEl = document.getElementById('wppilot-mcpb-download');
            if (mcpbEl) { mcpbEl.style.display = isDesktop ? '' : 'none'; }
            var pasteBlock = document.getElementById('wppilot-paste-block');
            if (pasteBlock) { pasteBlock.style.display = isDesktop ? 'none' : ''; }
            var pwNotice = document.getElementById('wppilot-prompt-password-notice');
            if (pwNotice) { pwNotice.style.display = isDesktop ? 'none' : ''; }
            var manualBtnWrap = document.getElementById('wppilot-manual-btn-wrap');
            if (manualBtnWrap) { manualBtnWrap.style.display = ''; }
            var npxlessToggle = document.getElementById('wppilot-npxless-toggle');
            if (npxlessToggle) {
                var showNpxless = client === 'claude-code' || client === 'codex';
                npxlessToggle.parentElement.style.display = showNpxless ? '' : 'none';
                if (!showNpxless) {
                    var npxlessConfig = document.getElementById('wppilot-npxless-config');
                    if (npxlessConfig) { npxlessConfig.style.display = 'none'; npxlessConfig.hidden = true; }
                    npxlessToggle.setAttribute('aria-expanded', 'false');
                }
            }

            var pathsEl = document.getElementById('wppilot-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length > 0) {
                var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
                keys.forEach(function (label) {
                    html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
                });
                html += '</ul>';
                pathsEl.innerHTML = html;
                pathsEl.style.display = '';
            } else {
                pathsEl.innerHTML = '';
                pathsEl.style.display = 'none';
            }
        }

        window.wppilotSetClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.wppilot-top-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            var content = document.getElementById('wppilot-connect-content');
            if (content) { content.style.display = ''; }
            var manualToggle = document.getElementById('wppilot-manual-toggle');
            if (manualToggle && clientLabels[key]) {
                manualToggle.textContent = <?php echo
                    wppilot_script_json(__('Manual configuration for', domain: 'wppilot'))
                ; ?> + ' ' + clientLabels[key];
            }
            renderConfig();
        };

        window.wppilotSetNpxlessClient = function (key, btn) {
            npxlessClient = key;
            document.querySelectorAll('.wppilot-npxless-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            renderNpxlessConfig();
        };

        function updateNameWarning(value) {
            var warning = document.getElementById('wppilot-name-warning');
            warning.style.display = value.length >= 25 ? 'block' : 'none';

            var suggestion = document.getElementById('wppilot-name-suggestion');
            var trimmed = value.trim();
            // A name an older version suggested ("wppilot-...") still identifies this server.
            var missingBrand = trimmed.length > 0 && trimmed.toLowerCase().indexOf('gobuildwp') === -1 && trimmed.toLowerCase().indexOf('wppilot') === -1;
            suggestion.style.display = missingBrand ? 'block' : 'none';
        }

        window.wppilotUpdateName = function (value) {
            mcpName = value.trim() || defaultName;
            var nameField = document.getElementById('wppilot-mcpb-name');
            if (nameField) { nameField.value = mcpName; }
            updateNameWarning(value);
            render();
        };

        window.wppilotShowPromptForDesktop = function (btn) {
            var mcpbEl = document.getElementById('wppilot-mcpb-download');
            if (mcpbEl) { mcpbEl.style.display = 'none'; }
            var pasteBlock = document.getElementById('wppilot-paste-block');
            if (pasteBlock) { pasteBlock.style.display = ''; }
            var pwNotice = document.getElementById('wppilot-prompt-password-notice');
            if (pwNotice) { pwNotice.style.display = ''; }
        };

        window.wppilotToggleServerName = function (btn) {
            var field = document.getElementById('wppilot-server-name-field');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                field.style.display = 'none';
                field.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                field.style.display = 'block';
                field.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                var input = document.getElementById('wppilot-mcp-name');
                if (input) { input.focus(); }
            }
        };

        window.wppilotToggleManualConfig = function (btn) {
            var panel = document.getElementById('wppilot-manual-config');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                panel.style.display = 'none';
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                panel.style.display = '';
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        // Open the manual-config section (never closes it) and scroll to it.
        // Used by the "manual configuration" link in the password notice.
        window.wppilotOpenManualConfig = function () {
            var panel = document.getElementById('wppilot-manual-config');
            if (panel === null) {
                return;
            }
            panel.style.display = '';
            panel.hidden = false;
            var toggle = document.getElementById('wppilot-manual-toggle');
            if (toggle !== null) {
                toggle.setAttribute('aria-expanded', 'true');
            }
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        window.wppilotToggleExpandPaste = function (btn) {
            var content = document.getElementById('wppilot-paste-content');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                content.classList.remove('is-expanded');
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = <?php

                echo wppilot_script_json(__('Show full text', domain: 'wppilot')); ?>;
            } else {
                content.classList.add('is-expanded');
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = <?php

                echo wppilot_script_json(__('Show less', domain: 'wppilot')); ?>;
            }
        };

        window.wppilotCopyPaste = function (btn) {
            window.wppilotClipboardCopy(document.getElementById('wppilot-paste-text').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo esc_js($copied_label); ?>';
                var warning = document.getElementById('wppilot-paste-copied-warning');
                if (warning) { warning.style.display = 'block'; }
                setTimeout(function () {
                    btn.textContent = orig;
                    if (warning) { warning.style.display = 'none'; }
                }, 4000);
            });
        };

        window.wppilotCopyConfig = function (btn) {
            window.wppilotClipboardCopy(document.getElementById('wppilot-config-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo esc_js($copied_label); ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        window.wppilotToggleNpxlessConfig = function (btn) {
            var panel = document.getElementById('wppilot-npxless-config');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                panel.style.display = 'none';
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                panel.style.display = '';
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        };

        window.wppilotCopyNpxlessConfig = function (btn) {
            window.wppilotClipboardCopy(document.getElementById('wppilot-npxless-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo esc_js($copied_label); ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        function renderNpxlessConfig() {
            var npxlessCodeEl = document.getElementById('wppilot-npxless-code');
            if (!npxlessCodeEl) { return; }

            var serverName = mcpName;
            var url = <?php

            echo wppilot_script_json($rest_url); ?>;
            var username = usernameValue;

            var authHeaderValue;
            if (passwordIsPlaceholder) {
                authHeaderValue = 'Basic <span class="wppilot-placeholder">BASE64_ENCODED_CREDENTIALS</span>';
            } else {
                var pwClean = passwordValue.replace(/\s+/g, '');
                var encoded = window.btoa(username + ':' + pwClean);
                authHeaderValue = 'Basic ' + encoded;
            }

            var indent = '  ';
            var hintEl = document.getElementById('wppilot-npxless-hint');
            var pathsEl = document.getElementById('wppilot-npxless-paths');
            var placeholder = 'BASE64_ENCODED_CREDENTIALS';
            var jsonQuote = function (value) {
                return JSON.stringify(value);
            };
            var tomlQuote = function (value) {
                return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
            };
            var code;

            if (npxlessClient === 'codex') {
                code = '[mcp_servers.' + serverName + ']\n' +
                    'url = ' + tomlQuote(url) + '\n' +
                    'http_headers = { Authorization = ' + tomlQuote(authHeaderValue.replace(/<[^>]+>/g, '')) + ' }';
                hintEl.textContent = <?php echo
                    wppilot_script_json(__('Add to your project’s .codex/config.toml file.', domain: 'wppilot'))
                ; ?>;
                pathsEl.innerHTML = '<ul style="margin:4px 0 0; padding-left:20px;">' +
                    '<li><strong><?php echo
                        esc_js(__('Project', domain: 'wppilot'))
                    ; ?></strong>: <code>.codex/config.toml</code></li>' +
                    '<li><strong><?php echo
                        esc_js(__('Global', domain: 'wppilot'))
                    ; ?></strong>: <code>~/.codex/config.toml</code></li>' +
                    '</ul>';
            } else {
                code = '{\n' +
                    indent + '"mcpServers": {\n' +
                    indent + indent + jsonQuote(serverName) + ': {\n' +
                    indent + indent + indent + '"type": "http",\n' +
                    indent + indent + indent + '"url": ' + jsonQuote(url) + ',\n' +
                    indent + indent + indent + '"headers": {\n' +
                    indent + indent + indent + indent + '"Authorization": ' + jsonQuote(authHeaderValue.replace(/<[^>]+>/g, '')) + '\n' +
                    indent + indent + indent + '}\n' +
                    indent + indent + '}\n' +
                    indent + '}\n' +
                    '}';
                hintEl.textContent = <?php echo
                    wppilot_script_json(__('Add to your project’s .mcp.json file.', domain: 'wppilot'))
                ; ?>;
                pathsEl.innerHTML = '<ul style="margin:4px 0 0; padding-left:20px;">' +
                    '<li><strong><?php echo
                        esc_js(__('Project', domain: 'wppilot'))
                    ; ?></strong>: <code>.mcp.json</code></li>' +
                    '</ul>';
            }

            npxlessCodeEl.textContent = code;
            if (passwordIsPlaceholder) {
                npxlessCodeEl.innerHTML = npxlessCodeEl.innerHTML.replace(
                    placeholder,
                    '<span class="wppilot-placeholder">' + placeholder + '</span>'
                );
            }
        }

        render();
    }());
    </script>
    <?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
}

function wppilot_render_mcp_dependency_inline_notice(?WP_Error $dependency_error): void
{
    if ($dependency_error === null) {
        return;
    }

    ?>
    <div class="wppilot-mcp-error-panel" role="alert">
        <h2><?php esc_html_e('GoBuildWP cannot expose MCP', domain: 'wppilot'); ?></h2>
        <p><?php echo esc_html($dependency_error->get_error_message()); ?></p>
    </div>
    <?php
}

/**
 * Warn when the web server does not forward HTTP Authorization headers to PHP.
 */
function wppilot_render_authorization_header_warning(): void
{
    // A security plugin switching Application Passwords off produces the same 401 as a stripped
    // header, and it is the likelier cause (Wordfence ships with it on), so it is named here too,
    // whichever connection method card is open.
    $pw_status = wppilot_app_passwords_status();
    $blocker = $pw_status['blocker'] ?? null;
    if (!$pw_status['available'] && $pw_status['reason'] === 'filtered') {
        ?>
        <div id="wppilot-app-passwords-blocked-warning" class="notice notice-error wppilot-keep" role="alert">
            <p><strong><?php echo esc_html(is_array($blocker) ? $blocker['message'] : $pw_status['message']); ?></strong></p>
            <?php if (is_array($blocker)): ?>
                <p>
                    <?php echo esc_html($blocker['remedy']); ?>
                    <?php if ($blocker['url'] !== ''): ?>
                        <a href="<?php echo esc_url($blocker['url']); ?>"><?php
                            /* translators: %s: plugin name, e.g. Wordfence. */
                            echo esc_html(sprintf(__('Open the %s setting', domain: 'wppilot'), $blocker['name']));
                        ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    if (wp_is_site_protected_by_basic_auth()) {
        return;
    }

    $test_url = rest_url('wp-site-health/v1/tests/authorization-header');
    $rest_nonce = (string) wp_create_nonce('wp_rest');
    ?>
    <div id="wppilot-authorization-header-warning" class="notice notice-warning wppilot-keep" role="alert" hidden>
        <p>
            <strong><?php esc_html_e(
                'The HTTP Authorization header is not reaching PHP.',
                domain: 'wppilot',
            ); ?></strong>
            <?php esc_html_e(
                'Application Password authentication may fail with unexpected 401 responses even when the credentials are correct.',
                domain: 'wppilot',
            ); ?>
        </p>
        <p>
            <?php esc_html_e(
                'For Apache, add this directive to the applicable virtual host or .htaccess configuration, then reload the server:',
                domain: 'wppilot',
            ); ?>
            <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code>
            <?php esc_html_e(
                'If you cannot change the server configuration, contact your hosting provider.',
                domain: 'wppilot',
            ); ?>
        </p>
    </div>
    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value emitted in this block goes through wppilot_script_json(), which hex-escapes <, >, & and quotes for <script> context. Plugin Check cannot recognise a project-local escaper. ?>
    <script>
    window.fetch(<?php

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wppilot_script_json() hex-escapes for script context.
    echo wppilot_script_json($test_url); ?>, {
        credentials: 'same-origin',
        headers: {
            'Authorization': 'Basic dXNlcjpwd2Q=',
            'X-WP-Nonce': <?php

            echo wppilot_script_json($rest_nonce); ?>
        }
    }).then(function (response) {
        if (!response.ok) {
            throw new Error('Authorization header test unavailable');
        }
        return response.json();
    }).then(function (result) {
        if (result && result.status !== 'good') {
            document.getElementById('wppilot-authorization-header-warning').hidden = false;
        }
    }).catch(function () {
        // A REST or network failure does not prove that Authorization forwarding is broken.
    });
    </script>
    <?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
}

/**
 * The last step: has a client actually arrived?
 *
 * Every step before this one happens somewhere else — paste a command, edit a
 * file, restart an app — and none of them report back here. So people finished
 * the setup with no way to tell success from failure except by trying their
 * agent and interpreting whatever it said. The two most common failures both
 * look like success from the client's side: a config still carrying the
 * placeholder password, and a client that was never restarted after being
 * configured. In both cases the client says "connected" and no tool works.
 *
 * This reads the connection ledger, which records a client only after it has
 * authenticated and completed an MCP handshake. A name here is therefore
 * evidence rather than inference: something reached this site, signed in, and
 * introduced itself.
 */
function wppilot_render_verify_step(): void
{
    $activity = function_exists('wppilot_dashboard_client_activity') ? wppilot_dashboard_client_activity() : [];

    $names = [];
    foreach ($activity as $client) {
        $label = is_array($client) ? (string) ($client['label'] ?? '') : '';
        if ($label !== '') {
            $names[] = $label;
        }
    }
    ?>
    <h2 class="wppilot-step-heading">
        <span class="wppilot-step-badge">3</span>
        <?php esc_html_e('Check it worked', domain: 'wppilot'); ?>
    </h2>

    <?php if ($names === []) { ?>
        <p class="description" style="margin:0 0 8px;">
            <strong><?php esc_html_e('No client has connected yet.', domain: 'wppilot'); ?></strong>
            <?php esc_html_e(
                'Most clients read their configuration only at startup, so restart yours after adding the server, then ask it to list this site\'s abilities. Reload this page to re-check.',
                domain: 'wppilot',
            ); ?>
        </p>
        <p class="description" style="margin:0;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wppilot-troubleshoot')); ?>"><?php esc_html_e(
                'Still nothing? Run diagnostics',
                domain: 'wppilot',
            ); ?></a>
        </p>
    <?php } else { ?>
        <p class="description" style="margin:0 0 8px;">
            <span class="wppilot-pill wppilot-pill--ready"><?php esc_html_e('Connected', domain: 'wppilot'); ?></span>
            <?php printf(
                /* translators: %s: comma-separated list of connected AI client names */
                esc_html__('Authenticated and introduced themselves: %s.', domain: 'wppilot'),
                '<strong>' . esc_html(implode(', ', $names)) . '</strong>',
            ); ?>
        </p>
        <p class="description" style="margin:0;">
            <a href="#wppilot-clients"><?php esc_html_e(
                'See each client and its credentials below',
                domain: 'wppilot',
            ); ?></a>
        </p>
    <?php } ?>
    <?php
}
