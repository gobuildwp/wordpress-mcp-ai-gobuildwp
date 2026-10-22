/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen, access-token method: the per-client snippets, steps and the
 * agent prompt, filled in with the new token when one was just created.
 *
 * Data: window.wppilotConnectTokens, printed by
 * wppilot_render_token_config_section().
 */
(function () {
    var C = window.wppilotConnectTokens || {};
    var configs = C.configs;
    var namePlaceholder = C.namePlaceholder;
    var defaultName = C.defaultName;
    var placeholderToken = C.placeholderToken;
    var isPlaceholder = C.isPlaceholder;
    var mcpName = defaultName;
    var client = '';
    var labels = C.labels;
    var endpointUrl = C.endpointUrl;
    var authLine = C.authLine;
    var tokenNotes = C.notes;

    function renderSteps(steps) {
        var list = document.getElementById('wppilot-token-config-steps');
        list.innerHTML = '';
        steps.forEach(function (step) {
            var li = document.createElement('li');
            li.style.margin = '0 0 6px';
            // A step that is nothing but a URL, a token or a header value is
            // something to copy rather than something to read, so it is set
            // as code — which also stops a long token wrapping mid-word.
            if (/^(https?:\/\/|wpp_|Bearer )/.test(step)) {
                var code = document.createElement('code');
                code.style.wordBreak = 'break-all';
                code.textContent = step;
                li.appendChild(code);
            } else {
                li.textContent = step;
            }
            list.appendChild(li);
        });
        list.style.display = '';
    }

    function render() {
        if (!client || !configs[client]) { return; }
        var cfg = configs[client];

        // A hosted web UI has no file to write, so it shows the click-through
        // steps instead of a snippet. Everything below the fork — the hint,
        // the agent prompt — is common to both.
        var hasSteps = !!(cfg.steps && cfg.steps.length);
        document.getElementById('wppilot-token-config-tab').style.display = hasSteps ? 'none' : '';
        document.getElementById('wppilot-token-config-steps').style.display = hasSteps ? '' : 'none';
        if (hasSteps) {
            renderSteps(cfg.steps);
            document.getElementById('wppilot-token-config-hint').innerHTML = cfg.hint;
            window.wppilotAgentPrompt('wppilot-token', {
                clientLabel: labels[client] || client,
                serverName: mcpName,
                url: endpointUrl,
                authLine: authLine,
                code: '',
                paths: {},
                isShell: false,
                steps: cfg.steps,
                hasSecret: true,
                notes: tokenNotes
            });
            return;
        }

        var codeEl = document.getElementById('wppilot-token-config-code');
        codeEl.textContent = cfg.code.split(namePlaceholder).join(mcpName);
        if (isPlaceholder) {
            codeEl.innerHTML = codeEl.innerHTML.split(placeholderToken).join(
                '<span class="wppilot-placeholder">' + placeholderToken + '</span>'
            );
        }
        document.getElementById('wppilot-token-config-hint').innerHTML = cfg.hint;

        var mergeNote = document.getElementById('wppilot-token-config-merge-note');
        if (mergeNote) { mergeNote.style.display = cfg.isShell ? 'none' : ''; }

        // Same values the snippet above was just built from, so the prompt
        // cannot describe a different client than the one on screen.
        window.wppilotAgentPrompt('wppilot-token', {
            clientLabel: labels[client] || client,
            serverName: mcpName,
            url: endpointUrl,
            authLine: authLine,
            code: cfg.code.split(namePlaceholder).join(mcpName),
            paths: cfg.paths,
            isShell: cfg.isShell,
            hasSecret: true,
            notes: tokenNotes
        });

        var pathsEl = document.getElementById('wppilot-token-config-paths');
        var keys = Object.keys(cfg.paths);
        if (keys.length === 0) {
            pathsEl.innerHTML = '';
            pathsEl.style.display = 'none';
            return;
        }
        var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
        keys.forEach(function (label) {
            html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
        });
        pathsEl.innerHTML = html + '</ul>';
        pathsEl.style.display = '';
    }

    window.wppilotSetTokenClient = function (key, btn) {
        client = key;
        document.querySelectorAll('.wppilot-token-client-tab').forEach(function (tab) {
            tab.classList.remove('active');
        });
        btn.classList.add('active');
        var content = document.getElementById('wppilot-token-connect-content');
        if (content) { content.style.display = ''; }
        render();
    };

    window.wppilotUpdateTokenName = function (value) {
        mcpName = value.trim() || defaultName;
        render();
    };

    // Open the snippet area on the first client rather than waiting for a
    // click. An empty panel under a "Connect Your AI Client" heading reads as
    // "nothing here", which is how the configuration went unnoticed.
    var first = document.querySelector('.wppilot-token-client-tab');
    if (first) {
        window.wppilotSetTokenClient(first.getAttribute('data-client'), first);
    }

    // Called from the reveal box above, which is rendered in a different
    // container and cannot reach this scope any other way.
    window.wppilotJumpToTokenSnippets = function () {
        var heading = document.getElementById('wppilot-token-snippets');
        if (heading) {
            heading.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };
}());
