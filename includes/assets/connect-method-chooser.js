/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen, step 1: the authentication method chooser. Shows one
 * method's panels at a time and opens on the method this request chose, and
 * after an access token was just created, brings its one-time reveal box into
 * view.
 *
 * Data: window.wppilotMethodChooser = { hasPassword, oauthAvailable,
 * defaultMethod, focusTokenCreated }.
 */
(function () {
    var config = window.wppilotMethodChooser || {};
    var hasPassword = config.hasPassword === true;
    var oauthAvailable = config.oauthAvailable === true;
    var defaultMethod = config.defaultMethod;
    // Re-query on every click so panels rendered in later containers (the step 3 section) are
    // toggled too. Step 3 opens for OAuth and for the access token immediately — both render a
    // usable snippet with a labelled placeholder — and for the password method only once a
    // password exists (otherwise the whole step 3 section stays hidden).
    function apply(method) {
        document.querySelectorAll('.wppilot-method-card').forEach(function (c) {
            c.classList.toggle('is-active', c.getAttribute('data-method') === method);
        });
        document.querySelectorAll('.wppilot-method-panel').forEach(function (p) {
            p.hidden = p.getAttribute('data-panel') !== method;
        });
        var step3 = document.getElementById('wppilot-step3');
        var visible = method === 'oauth'
            || method === 'token'
            || method === 'webapps'
            || (method === 'password' && hasPassword);
        if (step3) { step3.hidden = !visible; }
    }
    document.querySelectorAll('.wppilot-method-card').forEach(function (card) {
        card.addEventListener('click', function () { apply(card.getAttribute('data-method')); });
    });

    // Open on a method rather than on nothing. With no selection the whole of
    // step 2 stayed hidden, so the page read 1, 3, 4 — a numbered sequence
    // with a hole in it, which looks like a bug and hides the part that
    // actually connects a client. The application-password panel has nothing
    // to show until a password exists, so it is not used as the opening
    // choice in that state.
    // Deferred to DOM ready on purpose. The section it has to reveal — step 2
    // — is further down the page than the chooser, and running before the
    // parser reaches it would call getElementById on nothing, silently do
    // nothing, and leave the page reading 1, 3, 4. Waiting keeps that true
    // wherever this script is printed.
    function selectInitialMethod() {
        var initial = defaultMethod;
        if (initial === 'password' && !hasPassword) {
            initial = oauthAvailable ? 'oauth' : 'token';
        }
        apply(initial);

        // The Overview links here with a method already chosen. Without this
        // the link lands on whichever panel the server picked, which for a
        // visitor who clicked "Create an access token" is the wrong one.
        var requested = (window.location.hash || '').replace('#wppilot-', '').replace('-method', '');
            if (['oauth', 'password', 'token', 'webapps', 'cloud'].indexOf(requested) !== -1) {
            var card = document.querySelector('.wppilot-method-card[data-method="' + requested + '"]');
            if (card && !card.disabled) {
                apply(requested);
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', selectInitialMethod);
    } else {
        selectInitialMethod();
    }
})();

(function () {
    if (!(window.wppilotMethodChooser || {}).focusTokenCreated) { return; }
    // Creating a token posts the page back, and the browser restores the top
    // of the document — but the token is revealed several screens down, shown
    // once, and never recoverable. Landing above it is how someone loses the
    // only copy. Focus as well as scroll, so a screen reader lands there too.
    var box = document.getElementById('wppilot-token-created');
    if (!box) { return; }
    box.setAttribute('tabindex', '-1');
    window.requestAnimationFrame(function () {
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        box.focus({ preventScroll: true });
    });
}());
