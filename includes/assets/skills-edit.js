/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Skills editor: the Markdown toolbar and body placeholder for the CodeMirror
 * instance skills/admin.php exposes as window.wppilotSkillsEditor, and the
 * skill-name normalisation on blur and submit.
 */
(function () {
    // Body placeholder — visible only when content is empty.
    function wireBodyHint(cm) {
        var hint = document.querySelector('[data-wppilot-skills-body-hint]');
        if (!hint || !cm) {
            return;
        }
        var update = function () {
            if (cm.getValue().length > 0) {
                hint.classList.add('is-hidden');
            } else {
                hint.classList.remove('is-hidden');
            }
        };
        cm.on('change', update);
        update();
    }

    // Title normalisation (on blur + submit).
    var titleInput = document.querySelector('input[name="title"]');
    if (titleInput) {
        var normalize = function (raw) {
            return raw
                .toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9_-]/g, '')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        };
        var applyTitle = function () {
            var raw = titleInput.value;
            var normalized = normalize(raw);
            if (normalized !== raw) {
                titleInput.value = normalized;
            }
        };
        titleInput.addEventListener('blur', applyTitle);
        var form = titleInput.form;
        if (form) {
            form.addEventListener('submit', applyTitle);
        }
    }

    // Markdown toolbar — wraps the current selection (or inserts a
    // template) into the CodeMirror instance exposed as
    // window.wppilotSkillsEditor.
    function wireToolbar(cm) {
        var toolbar = document.querySelector('.wppilot-skills-md-toolbar');
        if (!toolbar || !cm) {
            return;
        }
        toolbar.addEventListener('click', function (event) {
            var btn = event.target.closest('button[data-md]');
            if (!btn) {
                return;
            }
            event.preventDefault();
            var action = btn.getAttribute('data-md');
            var selection = cm.getSelection();
            var hasSelection = selection.length > 0;
            var cursor = cm.getCursor();
            switch (action) {
                case 'bold':
                    cm.replaceSelection('**' + (hasSelection ? selection : 'bold text') + '**');
                    break;
                case 'italic':
                    cm.replaceSelection('*' + (hasSelection ? selection : 'italic text') + '*');
                    break;
                case 'code':
                    cm.replaceSelection('`' + (hasSelection ? selection : 'code') + '`');
                    break;
                case 'link':
                    cm.replaceSelection('[' + (hasSelection ? selection : 'text') + '](https://)');
                    break;
                case 'heading': {
                    var lineNo = cursor.line;
                    var line = cm.getLine(lineNo);
                    var match = line.match(/^(#{1,5})\s/);
                    var newLine;
                    if (match) {
                        newLine = '#'.repeat(match[1].length + 1) + ' ' + line.slice(match[0].length);
                        if (match[1].length >= 5) {
                            newLine = line.replace(/^#+\s/, '');
                        }
                    } else {
                        newLine = '# ' + line;
                    }
                    cm.replaceRange(newLine, { line: lineNo, ch: 0 }, { line: lineNo, ch: line.length });
                    break;
                }
                case 'list': {
                    var lineNo2 = cursor.line;
                    var line2 = cm.getLine(lineNo2);
                    var newLine2 = /^- /.test(line2) ? line2.slice(2) : '- ' + line2;
                    cm.replaceRange(newLine2, { line: lineNo2, ch: 0 }, { line: lineNo2, ch: line2.length });
                    break;
                }
            }
            cm.focus();
        });
    }

    function wireBodyExtras(cm) {
        wireToolbar(cm);
        wireBodyHint(cm);
    }

    if (window.wppilotSkillsEditor) {
        wireBodyExtras(window.wppilotSkillsEditor);
    } else {
        window.addEventListener('wppilot-skills-editor-ready', function () {
            wireBodyExtras(window.wppilotSkillsEditor);
        });
    }
})();
