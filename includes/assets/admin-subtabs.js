/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Section sub-tabs: lift the segmented control printed by
 * wppilot_render_admin_subtabs() to just under the screen's title. Without
 * script it stays above the title, which still works.
 */
document.addEventListener('DOMContentLoaded', function () {
    var holder = document.querySelector('.wppilot-subtabs-wrap');
    var nav = holder && holder.querySelector('.wppilot-subtabs');
    var h1 = document.querySelector('#wpbody-content .wrap:not(.wppilot-subtabs-wrap) h1');
    if (!nav || !h1) { return; }
    var row = document.createElement('div');
    row.className = 'wppilot-subtabs-row';
    row.appendChild(nav);
    // A title that shares a row with buttons is inline; the control needs its own line,
    // after the title's row of actions rather than wedged between them.
    var anchor = h1;
    while (anchor.nextElementSibling && anchor.nextElementSibling.matches('.page-title-action, a.button, button, .wppilot-title-actions')) {
        anchor = anchor.nextElementSibling;
    }
    var next = anchor.nextElementSibling;
    if (next && next.matches('p.wppilot-lede, p.description, .wppilot-lede')) {
        anchor = next;
    }
    anchor.insertAdjacentElement('afterend', row);
    holder.parentNode.removeChild(holder);
});
