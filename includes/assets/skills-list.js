/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Skills list: upload on file pick (up to 20 at a time) and the client-side
 * filter over the current page's rows.
 */
(function () {
    var file = document.getElementById('wppilot-skills-upload-file');
    var form = document.getElementById('wppilot-skills-upload-form');
    var MAX_UPLOAD = 20;
    if (file && form) {
        file.addEventListener('change', function () {
            if (!file.files || file.files.length === 0) {
                return;
            }
            if (file.files.length > MAX_UPLOAD) {
                alert('Too many files. Upload up to ' + MAX_UPLOAD + ' skills at a time.');
                file.value = '';
                return;
            }
            form.submit();
        });
    }

    // Client-side filter for the current page's rows. Searches slug and
    // description (visible text inside each row).
    var search = document.getElementById('wppilot-skills-search');
    var list = document.querySelector('[data-wppilot-skills-list]');
    if (search && list) {
        var rows = Array.prototype.slice.call(list.querySelectorAll('.wppilot-skills-d-row'));
        search.addEventListener('input', function () {
            var q = search.value.toLowerCase().trim();
            rows.forEach(function (row) {
                if (q === '') {
                    row.style.display = '';
                    return;
                }
                row.style.display = (row.textContent || '').toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();
