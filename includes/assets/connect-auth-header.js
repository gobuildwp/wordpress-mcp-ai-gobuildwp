/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen: ask Site Health whether the HTTP Authorization header reaches
 * PHP, and reveal the warning above the steps when it does not.
 *
 * Data: window.wppilotAuthHeaderCheck = { testUrl, nonce }.
 */
(function () {
    var config = window.wppilotAuthHeaderCheck || {};

    window.fetch(config.testUrl, {
        credentials: 'same-origin',
        headers: {
            'Authorization': 'Basic dXNlcjpwd2Q=',
            'X-WP-Nonce': config.nonce
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
}());
