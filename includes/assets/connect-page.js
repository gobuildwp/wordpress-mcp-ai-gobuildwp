/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen: the clipboard helper every panel uses, and the copy and
 * show/hide handlers its buttons call by name (onclick attributes), so these
 * stay global functions.
 *
 * Data: window.wppilotConnectPage = { copied }.
 */
// navigator.clipboard exists only in a secure context (HTTPS, or http://localhost). On a local
// site served over plain HTTP on a non-localhost host it is undefined, so fall back to a hidden
// textarea + execCommand('copy'), which needs no secure context.
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
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error('copy command was rejected'));
    });
};
function wppilotCopy(id, btn) {
    var text = document.getElementById(id).textContent;
    window.wppilotClipboardCopy(text).then(function() {
        var orig = btn.textContent;
        btn.textContent = (window.wppilotConnectPage || {}).copied;
        setTimeout(function() { btn.textContent = orig; }, 1500);
    });
}
function wppilotTogglePasswordName(btn) {
    var field = document.getElementById('wppilot-password-name-field');
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        field.style.display = 'none';
        field.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    } else {
        field.style.display = 'block';
        field.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        var input = document.getElementById('wppilot-password-name');
        if (input) { input.focus(); }
    }
}
function wppilotToggleUseExisting(btn) {
    var field = document.getElementById('wppilot-use-existing-field');
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        field.style.display = 'none';
        field.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    } else {
        field.style.display = 'block';
        field.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        var input = document.getElementById('wppilot-existing-password');
        if (input) { input.focus(); }
    }
}
