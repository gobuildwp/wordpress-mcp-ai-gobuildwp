/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen, web apps method: one tab per web app, filling in its OAuth
 * and access-token walkthroughs.
 *
 * Data: window.wppilotConnectWebApps = { apps, noToken }.
 */
(function () {
    var C = window.wppilotConnectWebApps || {};
    var apps = C.apps;
    var noToken = C.noToken;

    function fill(listId, steps) {
        var list = document.getElementById(listId);
        // The OAuth list is not rendered when the transport is unavailable.
        if (!list) {
            return;
        }
        list.innerHTML = '';
        steps.forEach(function (step) {
            var li = document.createElement('li');
            li.style.margin = '0 0 6px';
            // A bare URL, token or header value is something to copy, not to
            // read, so it is set as code and allowed to break anywhere.
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
    }

    window.wppilotSetWebApp = function (key, btn) {
        var app = apps[key];
        if (!app) { return; }
        document.querySelectorAll('.wppilot-webapp-tab').forEach(function (t) { t.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('wppilot-webapps-content').style.display = '';

        fill('wppilot-webapp-oauth-steps', app.oauth);

        var hasToken = app.token && app.token.length;
        document.getElementById('wppilot-webapp-token-wrap').style.display = hasToken ? '' : 'none';
        document.getElementById('wppilot-webapp-token-none').style.display = hasToken ? 'none' : '';
        if (hasToken) {
            fill('wppilot-webapp-token-steps', app.token);
            document.getElementById('wppilot-webapp-token-hint').textContent = app.tokenHint;
        } else {
            document.getElementById('wppilot-webapp-token-none').textContent = noToken;
        }
    };

    function selectFirstWebApp() {
        var first = document.querySelector('.wppilot-webapp-tab');
        if (first) { window.wppilotSetWebApp(first.getAttribute('data-app'), first); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', selectFirstWebApp);
    } else {
        selectFirstWebApp();
    }
}());
