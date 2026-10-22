/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Ask before turning agent access on from the admin-bar dropdown.
 *
 * Data: window.wppilotAdminBar.confirmEnable, the confirmation question.
 */
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('#wp-admin-bar-wppilot-mcp-toggle.wppilot-mcp-toggle-on > .ab-item');
    if (!toggle) {
        return;
    }
    toggle.addEventListener('click', function (event) {
        if (!window.confirm((window.wppilotAdminBar || {}).confirmEnable)) {
            event.preventDefault();
        }
    });
});
