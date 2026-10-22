/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Open the "Get Pro" entry of GoBuildWP's admin submenu in a new tab. WordPress
 * has no way to give a submenu link a target, so it is set here.
 */
(function() {
    var links = document.querySelectorAll('#toplevel_page_wppilot-connect .wp-submenu a');
    for (var i = 0; i < links.length; i++) {
        if (links[i].href.indexOf('gobuildwp.com/pro') !== -1) {
            links[i].target = '_blank';
            links[i].rel = 'noopener';
        }
    }
})();
