/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen, "Enable AI Abilities": confirm before saving a switch to
 * Developer Full Access, and show the description of the chosen profile.
 *
 * Data: window.wppilotEnableToggle = { savedProfile, confirmDeveloper }.
 */
(function () {
    var config = window.wppilotEnableToggle || {};

    document.getElementById('wppilot-settings-form').addEventListener('submit', function (e) {
        var cb = document.getElementById('wppilot-enable-checkbox');
        var profile = document.getElementById('wppilot-safety-profile');
        if (cb.checked && profile.value === 'developer' && (!cb.defaultChecked || profile.value !== config.savedProfile)) {
            var msg = config.confirmDeveloper;
            if (!confirm(msg)) {
                e.preventDefault();
            }
        }
    });
    document.getElementById('wppilot-safety-profile').addEventListener('change', function () {
        document.querySelectorAll('.wppilot-profile-description').forEach(function (node) {
            node.hidden = node.getAttribute('data-profile') !== this.value;
        }, this);
    });
}());
