<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Troubleshoot\UI;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render the troubleshooter panel: a run-checks report and a symptom picker with targeted
 * remedies (including the Auth Client ID generator). The Connect page's Step 4 is its single
 * home (one canonical place to debug a connection); $context prefixes the container id in case
 * another host ever needs it.
 *
 * $method scopes checks and symptoms to one connection method ('oauth', 'token' or 'password'). Pass ''
 * when the method is chosen at runtime — the Connect page keeps the attribute in sync with its
 * method chooser via window.wppilotTsSetMethod().
 */
// The method picker is an opt-in rendering variant of the same panel, not a second responsibility.
// @mago-expect lint:no-boolean-flag-parameter
function render_panel(string $context, string $method = '', bool $with_method_picker = false): void
{
    $prefix = 'wppilot-ts-' . sanitize_html_class($context);
    $registry = \WPPilot\Troubleshoot\ClientIds\registry();
    // Each symptom applies to one method (or both); the picker hides the rest.
    $symptoms = [
        'registration' => [
            'method' => 'oauth',
            'label' => __('My AI client shows a registration or sign-in service error', domain: 'wppilot'),
        ],
        'login-loop' => [
            'method' => 'oauth',
            'label' => __('The login page opens but authorizing loops or goes nowhere', domain: 'wppilot'),
        ],
        'was-working' => [
            'method' => 'oauth',
            'label' => __('It worked before, now requests fail with 401', domain: 'wppilot'),
        ],
        'no-tools' => [
            'method' => '',
            'label' => __('The client connects but shows no GoBuildWP tools', domain: 'wppilot'),
        ],
        'password-401' => [
            'method' => 'password',
            'label' => __('The Application Password method fails with 401', domain: 'wppilot'),
        ],
        'token-401' => [
            'method' => 'token',
            'label' => __('My access token is rejected with 401', domain: 'wppilot'),
        ],
    ];
    ?>
    <div
        class="wppilot-troubleshoot"
        id="<?php echo esc_attr($prefix); ?>"
        data-wppilot-ts-method="<?php echo esc_attr($method); ?>"
    >
        <div class="wppilot-ts-controls">
            <?php if ($with_method_picker): ?>
                <label class="wppilot-ts-method-pick">
                    <span class="wppilot-ts-method-label"><?php esc_html_e(
                        'How do you connect?',
                        domain: 'wppilot',
                    ); ?></span>
                    <select data-wppilot-ts="method-pick">
                        <option value="" <?php selected($method, current: ''); ?>><?php esc_html_e(
                            'Not sure (check both)',
                            domain: 'wppilot',
                        ); ?></option>
                        <option value="oauth" <?php selected($method, current: 'oauth'); ?>><?php esc_html_e(
                            'OAuth',
                            domain: 'wppilot',
                        ); ?></option>
                        <option value="password" <?php selected($method, current: 'password'); ?>><?php esc_html_e(
                            'Application Password',
                            domain: 'wppilot',
                        ); ?></option>
                        <option value="token" <?php selected($method, current: 'token'); ?>><?php esc_html_e(
                            'Access token',
                            domain: 'wppilot',
                        ); ?></option>
                    </select>
                </label>
            <?php endif; ?>
            <button type="button" class="button button-primary" data-wppilot-ts="run"><?php esc_html_e(
                'Run diagnostics',
                domain: 'wppilot',
            ); ?></button>
        </div>

        <div class="wppilot-ts-summary" data-wppilot-ts="summary" hidden></div>
        <div class="wppilot-ts-clean" data-wppilot-ts="clean" hidden></div>
        <ul class="wppilot-ts-report" data-wppilot-ts="report" aria-live="polite"></ul>
        <div class="wppilot-ts-copy-report" data-wppilot-ts="copy-report" hidden></div>

        <div class="wppilot-ts-symptoms" data-wppilot-ts="symptoms" hidden>
            <p class="wppilot-ts-symptoms-title"><?php esc_html_e(
                'What do you see in your AI client?',
                domain: 'wppilot',
            ); ?></p>
            <select data-wppilot-ts="symptom">
                <option value=""><?php esc_html_e('Pick the closest symptom…', domain: 'wppilot'); ?></option>
                <?php foreach ($symptoms as $key => $symptom): ?>
                    <option
                        value="<?php echo esc_attr($key); ?>"
                        data-method="<?php echo esc_attr($symptom['method']); ?>"
                    ><?php echo esc_html($symptom['label']); ?></option>
                <?php endforeach; ?>
            </select>

            <div class="wppilot-ts-branch" data-wppilot-ts-branch="registration" hidden>
                <p><?php esc_html_e(
                    'Registration is the automatic first step where the AI client creates its own OAuth client on this site. When it fails while the checks above pass, the requests from the AI client\'s servers are being blocked or rate-limited before they reach WordPress. You can skip that step entirely: mint a client ID here and paste it into the AI client.',
                    domain: 'wppilot',
                ); ?></p>
                <p class="wppilot-ts-mint-controls">
                    <select data-wppilot-ts="client">
                        <?php foreach ($registry as $key => $entry): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo
                                esc_html($entry['label'])
                            ; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" data-wppilot-ts="mint"><?php esc_html_e(
                        'Generate Auth Client ID',
                        domain: 'wppilot',
                    ); ?></button>
                </p>
                <div data-wppilot-ts="mint-result" hidden>
                    <p class="wppilot-ts-mint-row">
                        <code class="wppilot-ts-mint-id" data-wppilot-ts="mint-id"></code>
                        <button type="button" class="button" data-wppilot-ts="mint-copy"><?php esc_html_e(
                            'Copy',
                            domain: 'wppilot',
                        ); ?></button>
                    </p>
                    <p class="description" data-wppilot-ts="mint-hint"></p>
                    <p class="description"><?php esc_html_e(
                        'This ID stays valid until it is used (or deleted from Connected Apps, where it is listed as manually created).',
                        domain: 'wppilot',
                    ); ?></p>
                </div>
                <p class="description" data-wppilot-ts="mint-error" hidden></p>
            </div>

            <div class="wppilot-ts-branch" data-wppilot-ts-branch="login-loop" hidden>
                <p><?php esc_html_e(
                    'The authorization screen is a wp-admin page, so it needs the normal WordPress login cookie. If approving loops back to the login: finish the WordPress login in the same browser first, disable aggressive cookie/privacy extensions for this site, and if a maintenance or coming-soon plugin gates wp-admin, allow it for administrators.',
                    domain: 'wppilot',
                ); ?></p>
            </div>

            <div class="wppilot-ts-branch" data-wppilot-ts-branch="was-working" hidden>
                <p><?php esc_html_e(
                    'A connection that stops with 401 usually means one of: the permalink structure changed (the token audience changes with it — most clients recover on their own at the next refresh, otherwise reconnect once), the access was revoked from Connected Apps, or the site moved to plain HTTP (which disables the OAuth endpoints entirely). The checks above cover the last case.',
                    domain: 'wppilot',
                ); ?></p>
            </div>

            <div class="wppilot-ts-branch" data-wppilot-ts-branch="no-tools" hidden>
                <p><?php esc_html_e(
                    'The checks above already verify everything the server can see for this, including that AI Abilities are turned on. What the server cannot see is the client side: make sure the client points at the exact server URL from Step 3 — the OAuth URL and the Application Password URL are different endpoints — and restart the client so it reloads the server list.',
                    domain: 'wppilot',
                ); ?></p>
            </div>

            <div class="wppilot-ts-branch" data-wppilot-ts-branch="password-401" hidden>
                <p><?php esc_html_e(
                    'For the Application Password method a 401 means: the password was revoked (check the list at the bottom of the Connect page), Application Passwords are unavailable (see the checks above), the Authorization header is stripped before reaching PHP (the Connect page shows a dedicated warning when it detects that), or a security plugin or firewall in front of the site is blocking or altering the authenticated request (see the Security & edge layers check above).',
                    domain: 'wppilot',
                ); ?></p>
            </div>

            <div class="wppilot-ts-branch" data-wppilot-ts-branch="token-401" hidden>
                <p><?php esc_html_e(
                    'For an access token a 401 means one of five things: the token was revoked or has expired (both are visible in the token list on the Connect page — an expired one is labelled), the account that created it can no longer manage GoBuildWP, the value was truncated or line-wrapped when it was copied, the header is missing its "Bearer " prefix, or the Authorization header is stripped before reaching PHP (the Connect page warns when it detects that).',
                    domain: 'wppilot',
                ); ?></p>
                <p><?php esc_html_e(
                    'The curl snippet on the Connect page is the fastest way to tell those apart: it removes the client from the question entirely. If curl returns a tool list and your client still fails, the problem is in the client config, not the token.',
                    domain: 'wppilot',
                ); ?></p>
                <p><?php esc_html_e(
                    'A client that reports the server as "needs authentication" while the tools still work is a different thing and is not a failure: this site advertises OAuth metadata for the OAuth method, and some clients read that as a sign-in requirement even when a header already authenticates every call.',
                    domain: 'wppilot',
                ); ?></p>
            </div>
        </div>
    </div>
    <?php

    render_assets_once();
}

/**
 * Behavior (includes/assets/troubleshoot.js), shared by every panel instance on the page (event
 * delegation keyed on the data attributes), enqueued once no matter how many hosts render a panel.
 * Styles live in includes/assets/admin.css (the wppilot-ts-* section).
 */
function render_assets_once(): void
{
    if (wp_script_is('wppilot-troubleshoot')) {
        return;
    }
    $rest_nonce = (string) wp_create_nonce('wp_rest');
    $labels = [
        'ok' => __('OK', domain: 'wppilot'),
        'warning' => __('Warning', domain: 'wppilot'),
        'fail' => __('Problem', domain: 'wppilot'),
        'skipped' => __('Skipped', domain: 'wppilot'),
        'info' => __('Heads up', domain: 'wppilot'),
        'allOk' => __('All checks passed', domain: 'wppilot'),
        'clean' => __(
            'All automatic checks passed. This is necessary but not sufficient: an edge firewall or CDN can still block AI clients by IP or signature, which this probe cannot see from the server itself.',
            domain: 'wppilot',
        ),
        'caveat' => __('Checks passed, with something to note', domain: 'wppilot'),
        'error' => __('Could not run the checks. Reload the page and try again.', domain: 'wppilot'),
        'copy' => __('Copy', domain: 'wppilot'),
        'copied' => __('Copied!', domain: 'wppilot'),
        'copyReport' => __('Copy report for support', domain: 'wppilot'),
        'reportCopied' => __('Report copied', domain: 'wppilot'),
        'openFix' => __('Open the fix below', domain: 'wppilot'),
    ];

    \wppilot_enqueue_admin_script(
        'wppilot-troubleshoot',
        'troubleshoot.js',
        data_var: 'wppilotTroubleshoot',
        data: [
            'nonce' => $rest_nonce,
            'runUrl' => rest_url('wppilot/v1/troubleshoot/run-checks'),
            'mintUrl' => rest_url('wppilot/v1/troubleshoot/client-id'),
            'labels' => $labels,
        ],
    );
}
