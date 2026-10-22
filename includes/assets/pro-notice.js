/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * GoBuildWP Pro welcome notice: when its dismiss button is clicked, record the
 * dismissal through the notice's nonce-signed data-dismiss-url.
 */
(function() {
    var notices = document.querySelectorAll('.wppilot-pro-notice');
    for (var i = 0; i < notices.length; i++) {
        notices[i].addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('notice-dismiss')) {
                var url = this.getAttribute('data-dismiss-url');
                if (url) { fetch(url, {credentials: 'same-origin'}); }
            }
        });
    }
})();
