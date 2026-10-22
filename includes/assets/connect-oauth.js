/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen, OAuth method: the per-client tabs, connector and one-click
 * buttons, manual steps and the agent prompt.
 *
 * Data: window.wppilotConnectOauth (configs, client labels, endpoint, prompt
 * context and translated labels), printed by wppilot_render_oauth_config_section().
 */
(function () {
    var C = window.wppilotConnectOauth || {};
    var L = C.labels || {};
    var configs = C.configs;
    var client = '';
    var defaultName = C.defaultName;
    var mcpName = defaultName;
    var namePlaceholder = C.namePlaceholder;
    var clientLabels = C.clientLabels;
    var oauthEndpointUrl = C.endpointUrl;
    var oauthAuthLine = C.authLine;
    var oauthNotes = C.notes;
    var manualLabelPrefix = L.manualPrefix;
    var connectorLabelPrefix = L.connectorPrefix;
    var deeplinkLabelPrefix = L.deeplinkPrefix;
    var stepOpenLabel = L.stepOpen;
    var stepAddLabel = L.stepAdd;
    var stepAddNote = L.stepAddNote;
    var stepRunLabel = L.stepRun;
    var copyLabel = L.copy;
    var copiedLabel = L.copied;
    var stepSignInLabel = L.stepSignIn;
    var stepSignInNote = L.stepSignInNote;
    var stepSignInRestartLabel = L.stepSignInRestart;
    var stepSignInRestartNote = L.stepSignInRestartNote;
    var editConfigNote = L.editConfigNote;

    var manualOpen = false;

    function setDisplay(id, show) {
        document.getElementById(id).style.display = show ? '' : 'none';
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function withName(s) {
        return esc(String(s).split(namePlaceholder).join(mcpName));
    }

    // Config-file clients: turn the snippet + its file locations into a two-step guide (open the
    // config at its real path, then paste the snippet). Connector clients ship explicit steps.
    function signInStep(needsRestart) {
        return needsRestart
            ? { title: stepSignInRestartLabel, body: stepSignInRestartNote }
            : { title: stepSignInLabel, body: stepSignInNote };
    }

    function buildConfigSteps(cfg) {
        if (cfg.isShell) {
            return [{ title: stepRunLabel, code: cfg.code }, signInStep(false)];
        }
        var steps = [];
        var keys = cfg.paths ? Object.keys(cfg.paths) : [];
        if (keys.length) {
            var bodyHtml = keys.map(function (label) {
                return '<code>' + esc(cfg.paths[label]) + '</code> (' + esc(label) + ')';
            }).join('<br>');
            // Claude Desktop opens this exact file from its own UI, so point there first.
            if (client === 'claude-desktop') {
                bodyHtml = esc(editConfigNote) + '<br>' + bodyHtml;
            }
            steps.push({ title: stepOpenLabel, bodyHtml: bodyHtml });
        }
        var addStep = { title: stepAddLabel, body: stepAddNote, code: cfg.code };
        if (cfg.note) { addStep.noteHtml = cfg.note; }
        steps.push(addStep);
        steps.push(signInStep(true));
        return steps;
    }

    function renderSteps(steps) {
        var html = '';
        steps.forEach(function (s) {
            html += '<li style="margin:0 0 12px;"><strong>' + esc(s.title) + '</strong>';
            if (s.bodyHtml) {
                html += '<div style="margin-top:2px;">' + s.bodyHtml + '</div>';
            } else if (s.body) {
                html += '<div style="margin-top:2px;">' + withName(s.body) + '</div>';
            }
            if (s.copy) {
                html +=
                    '<div style="margin-top:6px;">' +
                    '<span style="display:inline-flex; align-items:center; gap:10px; max-width:100%; ' +
                    'background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:5px 6px 5px 12px;">' +
                    '<span style="font-weight:600; word-break:break-all;">' +
                    withName(s.copy) +
                    '</span><button type="button" class="button button-small" style="flex:none;" ' +
                    'onclick="wppilotOauthCopyChip(this)">' +
                    esc(copyLabel) +
                    '</button></span></div>';
            }
            if (s.code) {
                html +=
                    '<div class="wppilot-config-block" style="margin-top:6px;">' +
                    '<pre>' + withName(s.code) + '</pre>' +
                    '<button type="button" class="button wppilot-copy-btn" onclick="wppilotOauthCopyStep(this)">' +
                    esc(copyLabel) +
                    '</button></div>';
            }
            if (s.noteHtml) {
                html +=
                    '<div style="margin-top:6px; color:#646970; font-size:13px;">' +
                    s.noteHtml.split(namePlaceholder).join(esc(mcpName)) +
                    '</div>';
            }
            html += '</li>';
        });
        document.getElementById('wppilot-oauth-steps').innerHTML = html;
    }

    function render() {
        if (!client) { return; }
        var cfg = configs[client];
        if (!cfg) { return; }

        // A message-only client (e.g. a cloud client on a local site): show the explanation and
        // hide every interactive part, including the server-name field.
        var isNotice = cfg.kind === 'notice';
        setDisplay('wppilot-oauth-notice', isNotice);
        setDisplay('wppilot-oauth-name-wrap', !isNotice);
        if (isNotice) {
            document.getElementById('wppilot-oauth-notice').textContent = cfg.message || '';
            setDisplay('wppilot-oauth-connector-wrap', false);
            setDisplay('wppilot-oauth-deeplink-wrap', false);
            setDisplay('wppilot-oauth-hint', false);
            setDisplay('wppilot-oauth-steps', false);
            setDisplay('wppilot-oauth-manual-btn-wrap', false);
            setDisplay('wppilot-oauth-manual', false);
            setDisplay('wppilot-oauth-agent-prompt-wrap', false);
            return;
        }

        var isConnector = cfg.kind === 'connector';
        var hasDeeplink = !!cfg.deeplink;
        var hasSteps = !!(cfg.steps && cfg.steps.length);
        var hasCode = !!cfg.code;
        var hasManual = hasSteps || hasCode;
        var hasPrimary = isConnector || hasDeeplink;

        var label = clientLabels[client] || '';
        setDisplay('wppilot-oauth-connector-wrap', isConnector);
        if (isConnector) {
            var connBtn = document.getElementById('wppilot-oauth-connector-btn');
            connBtn.setAttribute('href', cfg.connector);
            connBtn.textContent = connectorLabelPrefix + ' ' + label;
        }

        setDisplay('wppilot-oauth-deeplink-wrap', hasDeeplink);
        if (hasDeeplink) {
            var dl = cfg.deeplink.split(namePlaceholder).join(encodeURIComponent(mcpName));
            var dlBtn = document.getElementById('wppilot-oauth-deeplink-btn');
            dlBtn.setAttribute('href', dl);
            dlBtn.textContent = deeplinkLabelPrefix + ' ' + label;
        }

        // The hint describes the OAuth connection method, so it only belongs with the one-click
        // primaries (connector / deeplink). For manual config-file clients the steps cover it.
        var showHint = hasPrimary && cfg.hint;
        var hintEl = document.getElementById('wppilot-oauth-hint');
        hintEl.innerHTML = showHint ? cfg.hint : '';
        hintEl.style.display = showHint ? '' : 'none';

        setDisplay('wppilot-oauth-steps', hasManual);
        if (hasSteps) {
            renderSteps(cfg.steps);
        } else if (hasCode) {
            renderSteps(buildConfigSteps(cfg));
        }

        setDisplay('wppilot-oauth-agent-prompt-wrap', true);
        window.wppilotAgentPrompt('wppilot-oauth', {
            clientLabel: label || client,
            serverName: mcpName,
            url: oauthEndpointUrl,
            authLine: oauthAuthLine,
            code: hasCode ? cfg.code.split(namePlaceholder).join(mcpName) : '',
            paths: cfg.paths || {},
            isShell: !!cfg.isShell,
            steps: hasSteps ? cfg.steps.map(function (step) {
                var body = (step.body || '').replace(/<[^>]+>/g, '');
                return step.copy ? body + ' ' + step.copy.split(namePlaceholder).join(mcpName) : body;
            }) : null,
            hasSecret: false,
            notes: oauthNotes
        });

        // The manual guide is a fallback behind a toggle when there is a one-click primary
        // (connector or deeplink); with no primary it is the only way in, so show it directly.
        setDisplay('wppilot-oauth-manual-btn-wrap', hasManual && hasPrimary);
        setDisplay('wppilot-oauth-manual', hasManual && (!hasPrimary || manualOpen));
        document.getElementById('wppilot-oauth-manual-toggle')
            .setAttribute('aria-expanded', manualOpen ? 'true' : 'false');
    }

    // Open on the first client instead of an empty panel. Everything under
    // the step 2 heading — the steps, the hint, the "let your AI coder do it"
    // prompt — lives inside #wppilot-oauth-content, which stayed display:none
    // until a tab was clicked. The heading was therefore followed by a row of
    // tabs and nothing else, which reads as "this method has no instructions".
    function selectFirstOauthClient() {
        var first = document.querySelector('.wppilot-oauth-tab');
        if (first) {
            window.wppilotOauthSetClient(first.getAttribute('data-client'), first);
        }
    }

    window.wppilotOauthSetClient = function (key, btn) {
        client = key;
        manualOpen = false;
        document.querySelectorAll('.wppilot-oauth-tab').forEach(function (t) { t.classList.remove('active'); });
        btn.classList.add('active');
        if (clientLabels[key]) {
            document.getElementById('wppilot-oauth-manual-toggle').textContent =
                manualLabelPrefix + ' ' + clientLabels[key];
        }
        document.getElementById('wppilot-oauth-content').style.display = '';
        render();
    };

    window.wppilotOauthToggleManual = function (btn) {
        manualOpen = !manualOpen;
        btn.setAttribute('aria-expanded', manualOpen ? 'true' : 'false');
        setDisplay('wppilot-oauth-manual', manualOpen);
    };

    function updateOauthNameWarning(value) {
        document.getElementById('wppilot-oauth-name-warning').style.display = value.length >= 25 ? 'block' : 'none';
        var trimmed = value.trim();
        var missing = trimmed.length > 0 && trimmed.toLowerCase().indexOf('wppilot') === -1;
        document.getElementById('wppilot-oauth-name-suggestion').style.display = missing ? 'block' : 'none';
    }

    window.wppilotOauthUpdateName = function (value) {
        mcpName = value.trim() || defaultName;
        updateOauthNameWarning(value);
        render();
    };

    window.wppilotOauthToggleName = function (btn) {
        var field = document.getElementById('wppilot-oauth-name-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        field.style.display = expanded ? 'none' : 'block';
        field.hidden = expanded;
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    };

    window.wppilotOauthCopyStep = function (btn) {
        var pre = btn.previousElementSibling;
        if (!pre) { return; }
        window.wppilotClipboardCopy(pre.textContent).then(function () {
            var orig = btn.textContent;
            btn.textContent = copiedLabel;
            setTimeout(function () { btn.textContent = orig; }, 1500);
        });
    };

    window.wppilotOauthCopyChip = function (btn) {
        var value = btn.previousElementSibling;
        if (!value) { return; }
        window.wppilotClipboardCopy(value.textContent).then(function () {
            var orig = btn.textContent;
            btn.textContent = copiedLabel;
            setTimeout(function () { btn.textContent = orig; }, 1500);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', selectFirstOauthClient);
    } else {
        selectFirstOauthClient();
    }
}());
