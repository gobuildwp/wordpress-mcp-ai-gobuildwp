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
 * Styles + behavior, shared by every panel instance on the page (event delegation keyed on the
 * data attributes), printed once no matter how many hosts render a panel.
 */
function render_assets_once(): void
{
    static $printed = false;
    // @mago-expect analysis:impossible-condition
    if ($printed) {
        return;
    }
    $printed = true;
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
    ?>
    <?php // Styles for this screen live in includes/assets/admin.css (the wppilot-ts-* section). ?>
    <script>
    (function () {
        var nonce = <?php echo wp_json_encode($rest_nonce); ?>;
        var runUrl = <?php echo wp_json_encode(rest_url('wppilot/v1/troubleshoot/run-checks')); ?>;
        var mintUrl = <?php echo wp_json_encode(rest_url('wppilot/v1/troubleshoot/client-id')); ?>;
        var labels = <?php echo wp_json_encode($labels); ?>;
        var glyphs = { ok: '✓', warning: '!', fail: '✕', skipped: '–', info: 'i' };

        // The panel now lives on its own Troubleshoot page, not only inside the Connect page, so it
        // must provide its own clipboard helper. navigator.clipboard needs a secure context (HTTPS
        // or localhost); on plain HTTP fall back to a hidden textarea + execCommand('copy').
        if (!window.wppilotClipboardCopy) {
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
                    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                    document.body.removeChild(ta);
                    ok ? resolve() : reject(new Error('copy command was rejected'));
                });
            };
        }

        function post(url, body) {
            return window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify(body || {})
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
        }

        function statusKey(status) {
            return Object.prototype.hasOwnProperty.call(glyphs, status) ? status : 'skipped';
        }

        function renderSkeleton(list) {
            list.textContent = '';
            for (var i = 0; i < 3; i++) {
                var li = document.createElement('li');
                li.className = 'wppilot-ts-skeleton';
                list.appendChild(li);
            }
        }

        function renderSummary(summary, counts) {
            summary.textContent = '';
            ['fail', 'warning', 'info'].filter(function (key) { return counts[key] > 0; }).forEach(function (key) {
                var chip = document.createElement('span');
                chip.className = 'wppilot-ts-chip is-' + key;
                chip.textContent = counts[key] + ' ' + labels[key];
                summary.appendChild(chip);
            });
            summary.hidden = false;
        }

        function buildRow(c, i) {
            var status = statusKey(c.status);
            var li = document.createElement('li');
            li.className = 'wppilot-ts-row is-' + status;
            li.style.animationDelay = (i * 45) + 'ms';

            var dot = document.createElement('span');
            dot.className = 'wppilot-ts-dot';
            dot.textContent = glyphs[status];
            dot.setAttribute('aria-label', labels[status] || status);
            li.appendChild(dot);

            var body = document.createElement('div');
            body.className = 'wppilot-ts-row-body';
            var title = document.createElement('div');
            title.className = 'wppilot-ts-row-title';
            title.textContent = c.label;
            body.appendChild(title);
            var msg = document.createElement('div');
            msg.className = 'wppilot-ts-row-msg';
            msg.textContent = c.message;
            body.appendChild(msg);

            if (c.remedy) {
                var fix = document.createElement('div');
                fix.className = 'wppilot-ts-remedy';
                fix.textContent = '→ ' + c.remedy;
                // A remedy that lives inside a symptom branch gets a button that opens it,
                // so nobody has to figure out which entry of the picker hides the fix.
                if (c.action) {
                    var open = document.createElement('button');
                    open.type = 'button';
                    open.className = 'button button-small';
                    open.textContent = labels.openFix;
                    open.setAttribute('data-wppilot-ts-open', c.action);
                    fix.appendChild(document.createElement('br'));
                    fix.appendChild(open);
                }
                // A ready-to-send text (e.g. the message for the hosting support) rendered
                // as a copyable block right inside the remedy.
                if (c.copy) {
                    var copyWrap = document.createElement('div');
                    copyWrap.className = 'wppilot-ts-copy';
                    var copyText = document.createElement('pre');
                    copyText.className = 'wppilot-ts-copy-text';
                    copyText.textContent = c.copy;
                    copyWrap.appendChild(copyText);
                    var copyBtn = document.createElement('button');
                    copyBtn.type = 'button';
                    copyBtn.className = 'button button-small';
                    copyBtn.textContent = labels.copy;
                    copyBtn.setAttribute('data-wppilot-ts', 'remedy-copy');
                    copyWrap.appendChild(copyBtn);
                    fix.appendChild(copyWrap);
                }
                body.appendChild(fix);
            }

            li.appendChild(body);
            return li;
        }

        function renderCopyReport(container, report) {
            container.textContent = '';
            if (!report) { container.hidden = true; return; }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button';
            btn.textContent = labels.copyReport;
            btn.setAttribute('data-wppilot-ts', 'report-copy');
            container.setAttribute('data-report', report);
            container.appendChild(btn);
            container.hidden = false;
        }

        // Outcome-driven: a clean run (all passed, nothing in front) shows one calm line; a run that
        // passed but detected a security/edge layer shows a caveat plus that row; a run with any
        // fail/warning shows only the problem rows. A healthy run stays quiet instead of listing
        // every green check, and the copy-report button appears whenever there is something to send.
        function renderReport(els, data) {
            var checks = data.checks || [];
            els.report.textContent = '';
            els.summary.hidden = true;
            els.summary.textContent = '';
            els.clean.hidden = true;
            els.clean.className = 'wppilot-ts-clean';
            els.copyReport.hidden = true;
            els.copyReport.textContent = '';

            var problems = checks.filter(function (c) { return c.status === 'fail' || c.status === 'warning'; });
            var infos = checks.filter(function (c) { return c.status === 'info'; });

            if (problems.length === 0 && infos.length === 0) {
                els.clean.textContent = glyphs.ok + '  ' + labels.clean;
                els.clean.hidden = false;
                return;
            }

            if (problems.length === 0) {
                // Passed, but a security/edge layer is in front: a heads-up (amber), not a green pass.
                els.clean.className = 'wppilot-ts-clean is-caveat';
                els.clean.textContent = labels.caveat;
                els.clean.hidden = false;
                infos.forEach(function (c, i) { els.report.appendChild(buildRow(c, i)); });
                renderCopyReport(els.copyReport, data.report);
                return;
            }

            problems.concat(infos).forEach(function (c, i) { els.report.appendChild(buildRow(c, i)); });
            var counts = { fail: 0, warning: 0, info: 0 };
            checks.forEach(function (c) { if (counts[c.status] !== undefined) { counts[c.status] += 1; } });
            renderSummary(els.summary, counts);
            renderCopyReport(els.copyReport, data.report);
        }

        // Clear a previous run's output. Used when the method changes, since the results, report,
        // and symptom guidance all belonged to the old method.
        function resetPanel(panel) {
            var report = panel.querySelector('[data-wppilot-ts="report"]');
            var summary = panel.querySelector('[data-wppilot-ts="summary"]');
            var clean = panel.querySelector('[data-wppilot-ts="clean"]');
            var copyReport = panel.querySelector('[data-wppilot-ts="copy-report"]');
            var symptoms = panel.querySelector('[data-wppilot-ts="symptoms"]');
            var symptomSelect = panel.querySelector('[data-wppilot-ts="symptom"]');
            if (report) { report.textContent = ''; }
            if (summary) { summary.hidden = true; summary.textContent = ''; }
            if (clean) { clean.hidden = true; clean.className = 'wppilot-ts-clean'; }
            if (copyReport) { copyReport.hidden = true; copyReport.textContent = ''; }
            if (symptoms) { symptoms.hidden = true; }
            if (symptomSelect) { symptomSelect.value = ''; }
            panel.querySelectorAll('[data-wppilot-ts-branch]').forEach(function (b) { b.hidden = true; });
        }

        function applyMethod(panel) {
            var method = panel.getAttribute('data-wppilot-ts-method') || '';
            var select = panel.querySelector('[data-wppilot-ts="symptom"]');
            if (!select) { return; }
            var selectedHidden = false;
            select.querySelectorAll('option[data-method]').forEach(function (option) {
                var own = option.getAttribute('data-method') || '';
                var show = method === '' || own === '' || own === method;
                option.hidden = !show;
                if (!show && option.selected) { selectedHidden = true; }
            });
            if (selectedHidden) {
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        // The Connect page calls this when its method chooser changes, so the panel's checks
        // and symptom list follow the method the user is actually setting up.
        window.wppilotTsSetMethod = function (method) {
            document.querySelectorAll('.wppilot-troubleshoot').forEach(function (panel) {
                var known = method === 'oauth' || method === 'password' || method === 'token';
            panel.setAttribute('data-wppilot-ts-method', known ? method : '');
                applyMethod(panel);
            });
        };

        document.querySelectorAll('.wppilot-troubleshoot').forEach(applyMethod);

        document.addEventListener('click', function (e) {
            var target = e.target;
            if (!(target instanceof Element)) { return; }
            var panel = target.closest('.wppilot-troubleshoot');
            if (!panel) { return; }

            var openSymptom = target.getAttribute('data-wppilot-ts-open');
            if (openSymptom) {
                var symptomSelect = panel.querySelector('[data-wppilot-ts="symptom"]');
                symptomSelect.value = openSymptom;
                symptomSelect.dispatchEvent(new Event('change', { bubbles: true }));
                var branch = panel.querySelector('[data-wppilot-ts-branch="' + openSymptom + '"]');
                if (branch) { branch.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                return;
            }

            var action = target.getAttribute('data-wppilot-ts');
            if (!action) { return; }

            if (action === 'run') {
                var els = {
                    report: panel.querySelector('[data-wppilot-ts="report"]'),
                    summary: panel.querySelector('[data-wppilot-ts="summary"]'),
                    clean: panel.querySelector('[data-wppilot-ts="clean"]'),
                    copyReport: panel.querySelector('[data-wppilot-ts="copy-report"]')
                };
                var method = panel.getAttribute('data-wppilot-ts-method') || '';
                els.summary.hidden = true;
                els.clean.hidden = true;
                els.copyReport.hidden = true;
                renderSkeleton(els.report);
                target.disabled = true;
                // Lock the method picker while a run is in flight, so it cannot change under a
                // response that belongs to the method the run started with.
                var methodPick = panel.querySelector('[data-wppilot-ts="method-pick"]');
                if (methodPick) { methodPick.disabled = true; }
                post(runUrl, method === '' ? {} : { method: method }).then(function (r) {
                    target.disabled = false;
                    if (methodPick) { methodPick.disabled = false; }
                    if (!r.ok || !r.json.checks) { els.report.textContent = labels.error; return; }
                    renderReport(els, r.json);
                    // The symptom guide points at "the checks above", so it only appears once a run
                    // has produced them.
                    var symptoms = panel.querySelector('[data-wppilot-ts="symptoms"]');
                    if (symptoms) { symptoms.hidden = false; }
                }).catch(function () {
                    target.disabled = false;
                    if (methodPick) { methodPick.disabled = false; }
                    els.report.textContent = labels.error;
                });
            }

            if (action === 'mint') {
                var client = panel.querySelector('[data-wppilot-ts="client"]').value;
                var out = panel.querySelector('[data-wppilot-ts="mint-result"]');
                var err = panel.querySelector('[data-wppilot-ts="mint-error"]');
                target.disabled = true;
                post(mintUrl, { client: client }).then(function (r) {
                    target.disabled = false;
                    if (!r.ok || !r.json.client_id) {
                        err.textContent = r.json && r.json.message ? r.json.message : labels.error;
                        err.hidden = false;
                        out.hidden = true;
                        return;
                    }
                    err.hidden = true;
                    panel.querySelector('[data-wppilot-ts="mint-id"]').textContent = r.json.client_id;
                    panel.querySelector('[data-wppilot-ts="mint-hint"]').textContent = r.json.field_hint;
                    out.hidden = false;
                }).catch(function () {
                    target.disabled = false;
                    err.textContent = labels.error;
                    err.hidden = false;
                });
            }

            if (action === 'remedy-copy') {
                var block = target.closest('.wppilot-ts-copy').querySelector('.wppilot-ts-copy-text');
                window.wppilotClipboardCopy(block.textContent).then(function () {
                    var origLabel = target.textContent;
                    target.textContent = labels.copied;
                    setTimeout(function () { target.textContent = origLabel; }, 1500);
                });
            }

            if (action === 'report-copy') {
                var reportEl = panel.querySelector('[data-wppilot-ts="copy-report"]');
                window.wppilotClipboardCopy(reportEl.getAttribute('data-report') || '').then(function () {
                    var orig = target.textContent;
                    target.textContent = labels.reportCopied;
                    setTimeout(function () { target.textContent = orig; }, 1500);
                });
            }

            if (action === 'mint-copy') {
                var id = panel.querySelector('[data-wppilot-ts="mint-id"]').textContent;
                window.wppilotClipboardCopy(id).then(function () {
                    var orig = target.textContent;
                    target.textContent = labels.copied;
                    setTimeout(function () { target.textContent = orig; }, 1500);
                });
            }
        });

        document.addEventListener('change', function (e) {
            var target = e.target;
            if (!(target instanceof Element)) { return; }
            var tsAttr = target.getAttribute('data-wppilot-ts');
            if (tsAttr === 'method-pick') {
                if (window.wppilotTsSetMethod) { window.wppilotTsSetMethod(target.value); }
                var mpPanel = target.closest('.wppilot-troubleshoot');
                if (mpPanel) { resetPanel(mpPanel); }
                return;
            }
            if (tsAttr !== 'symptom') { return; }
            var panel = target.closest('.wppilot-troubleshoot');
            panel.querySelectorAll('[data-wppilot-ts-branch]').forEach(function (branch) {
                branch.hidden = branch.getAttribute('data-wppilot-ts-branch') !== target.value;
            });
        });
    })();
    </script>
    <?php
}
