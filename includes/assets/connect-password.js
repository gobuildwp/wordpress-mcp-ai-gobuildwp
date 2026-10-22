/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen, application-password method: the per-client snippets, the
 * paste-to-agent paragraph, the server-name field, the npx-free alternative
 * and the agent prompt. The buttons call the window.wppilot* handlers by name.
 *
 * Data: window.wppilotConnectPassword, printed by wppilot_render_config_section().
 * labels.projectHtml and labels.globalHtml are HTML-escaped: they are inserted
 * with innerHTML.
 */
(function () {
    var C = window.wppilotConnectPassword || {};
    var L = C.labels || {};
    var configs = C.configs;
    var clientLabels = C.clientLabels;
    var client = '';
    var defaultName = C.defaultName;
    var pasteTemplate = C.pasteTemplate;
    var mcpName = C.defaultName;
    var npxlessClient = 'claude';
    var namePlaceholder = C.namePlaceholder;
    var passwordSentinel = C.passwordSentinel;
    var passwordValue = C.passwordValue;
    var passwordIsPlaceholder = C.passwordIsPlaceholder;
    var usernameValue = C.username;
    var passwordEndpointUrl = C.endpointUrl;
    var passwordAuthLine = C.authLine;
    var passwordNotes = C.notes;

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
            manualToggle.textContent = L.manualPrefix + ' ' + clientLabels[key];
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
            btn.textContent = L.showFullText;
        } else {
            content.classList.add('is-expanded');
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = L.showLess;
        }
    };

    window.wppilotCopyPaste = function (btn) {
        window.wppilotClipboardCopy(document.getElementById('wppilot-paste-text').textContent).then(function () {
            var orig = btn.textContent;
            btn.textContent = L.copied;
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
            btn.textContent = L.copied;
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
            btn.textContent = L.copied;
            setTimeout(function () { btn.textContent = orig; }, 1500);
        });
    };

    function renderNpxlessConfig() {
        var npxlessCodeEl = document.getElementById('wppilot-npxless-code');
        if (!npxlessCodeEl) { return; }

        var serverName = mcpName;
        var url = passwordEndpointUrl;
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
            hintEl.textContent = L.codexHint;
            pathsEl.innerHTML = '<ul style="margin:4px 0 0; padding-left:20px;">' +
                '<li><strong>' + L.projectHtml + '</strong>: <code>.codex/config.toml</code></li>' +
                '<li><strong>' + L.globalHtml + '</strong>: <code>~/.codex/config.toml</code></li>' +
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
            hintEl.textContent = L.mcpJsonHint;
            pathsEl.innerHTML = '<ul style="margin:4px 0 0; padding-left:20px;">' +
                '<li><strong>' + L.projectHtml + '</strong>: <code>.mcp.json</code></li>' +
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
