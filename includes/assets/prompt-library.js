/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Prompts screen: copy buttons, the builder picker that rewrites the first
 * line of every brief, and the industry filter.
 *
 * Data: window.wppilotPromptLibrary.builderLinePrefix, the text before the
 * builder's name on the first line of a brief.
 */
(function () {
    function fallbackClipboardCopy(text) {
        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            var active = document.activeElement;
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.setAttribute('aria-hidden', 'true');
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            var copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }

            document.body.removeChild(textarea);
            if (active && typeof active.focus === 'function') {
                active.focus();
            }
            copied ? resolve() : reject(new Error('copy command was rejected'));
        });
    }

    // The Prompts screen is independent from Connect and Troubleshoot, so
    // it must own the helper it calls. Also fall back when the modern API
    // exists but rejects because of browser permissions or an HTTP origin.
    if (!window.wppilotClipboardCopy) {
        window.wppilotClipboardCopy = function (text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text).catch(function () {
                    return fallbackClipboardCopy(text);
                });
            }
            return fallbackClipboardCopy(text);
        };
    }

    function showCopyState(button, label) {
        var original = button.getAttribute('data-label') || button.textContent;
        button.setAttribute('data-label', original);
        button.textContent = label;
        setTimeout(function () { button.textContent = original; }, 1800);
    }

    // The builder picker rewrites the first line of every brief. The
    // choice is remembered per browser: a person building Elementor sites
    // should not have to say so on every visit.
    var picker = document.getElementById('wppilot-prompt-builder');
    var prefix = (window.wppilotPromptLibrary || {}).builderLinePrefix || '';
    var storageKey = 'wppilotPromptBuilder';

    function applyBuilder() {
        if (!picker) {
            return;
        }
        var label = picker.options[picker.selectedIndex] ? picker.options[picker.selectedIndex].text : '';
        document.querySelectorAll('.wppilot-prompt__builder').forEach(function (line) {
            line.textContent = prefix + label;
        });
        try {
            window.localStorage.setItem(storageKey, picker.value);
        } catch (error) {
            // Storage can be unavailable; the picker still works for this page view.
        }
    }

    if (picker) {
        try {
            var remembered = window.localStorage.getItem(storageKey);
            if (remembered && picker.querySelector('option[value="' + remembered + '"]')) {
                picker.value = remembered;
            }
        } catch (error) {
            // Ignore: no storage, no memory, nothing lost.
        }
        picker.addEventListener('change', applyBuilder);
        applyBuilder();
    }

    // Filtering is over the card's own visible text - industry, title,
    // description, signature - rather than the whole brief, so typing
    // "booking" finds the booking sites instead of every brief that happens
    // to mention a booking form somewhere in its body.
    var filterInput = document.getElementById('wppilot-prompt-filter');
    var filterCount = document.getElementById('wppilot-prompt-count');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.wppilot-prompt'));
    var haystacks = cards.map(function (card) {
        var head = card.querySelector('.wppilot-prompt__head');
        return (head ? head.innerText : card.innerText).toLowerCase();
    });

    var chips = Array.prototype.slice.call(document.querySelectorAll('.wppilot-prompt-sectors [data-sector]'));
    var activeSector = chips.length ? chips[0].getAttribute('data-sector') : '';

    // A search looks across every industry; with the box empty, only the
    // chosen industry is shown.
    function applyFilter() {
        var term = (filterInput.value || '').trim().toLowerCase();
        var shown = 0;

        cards.forEach(function (card, index) {
            var match = term === '' || haystacks[index].indexOf(term) !== -1;
            card.hidden = !match;
            if (match) { shown++; }
        });

        document.querySelectorAll('.wppilot-prompt-sector').forEach(function (section) {
            var visible = section.querySelectorAll('.wppilot-prompt:not([hidden])').length;
            section.hidden = term === ''
                ? section.getAttribute('data-sector') !== activeSector
                : visible === 0;
        });

        chips.forEach(function (chip) {
            var on = term === '' && chip.getAttribute('data-sector') === activeSector;
            chip.classList.toggle('active', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        filterCount.textContent = term === ''
            ? ''
            : shown + ' of ' + cards.length;
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            activeSector = chip.getAttribute('data-sector');
            if (filterInput) { filterInput.value = ''; }
            applyFilter();
        });
    });

    if (filterInput) {
        filterInput.addEventListener('input', applyFilter);
    }

    document.querySelectorAll('.wppilot-prompt-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = document.getElementById(btn.getAttribute('data-target'));
            if (!body) {
                showCopyState(btn, btn.getAttribute('data-failed'));
                return;
            }
            window.wppilotClipboardCopy(body.textContent).then(function () {
                showCopyState(btn, btn.getAttribute('data-copied'));
            }).catch(function () {
                showCopyState(btn, btn.getAttribute('data-failed'));
            });
        });
    });
})();
