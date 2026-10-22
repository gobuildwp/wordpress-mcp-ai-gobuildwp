/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Connect screen: the "let your AI coder set this up" prompt shared by every
 * connection method. Defines window.wppilotAgentPrompt (the builder each method
 * panel calls with its own snippet), window.wppilotToggleAgentPrompt and
 * window.wppilotCopyAgentPrompt.
 *
 * Data: window.wppilotAgentPromptStrings, the translated sentences.
 */
(function () {
    var S = window.wppilotAgentPromptStrings || {};

    // The context comes from whichever method panel is rendering: the same
    // fields every panel already has for its own snippet, so no panel has to
    // describe its configuration twice.
    window.wppilotAgentPrompt = function (prefix, ctx) {
        var el = document.getElementById(prefix + '-agent-prompt');
        if (!el) { return; }

        var lines = [
            S.intro.replace('%s', ctx.clientLabel),
            '',
            S.serverName + ' ' + ctx.serverName,
            S.serverUrl + ' ' + ctx.url,
            S.auth + ' ' + ctx.authLine,
            ''
        ];

        if (ctx.steps && ctx.steps.length) {
            lines.push(S.uiSteps);
            ctx.steps.forEach(function (step, i) {
                lines.push((i + 1) + '. ' + step);
            });
        } else if (ctx.code) {
            lines.push(ctx.isShell ? S.runThis : S.writeThis);
            lines.push('');
            lines.push(ctx.code);
            var paths = ctx.paths || {};
            var keys = Object.keys(paths);
            if (!ctx.isShell && keys.length) {
                lines.push('');
                lines.push(S.writeTo);
                keys.forEach(function (label) {
                    lines.push('- ' + label + ': ' + paths[label]);
                });
            }
        }

        lines.push('');
        lines.push(S.rules);
        lines.push(S.ruleExact);
        if (!ctx.isShell) { lines.push(S.ruleMerge); }
        lines.push(S.ruleRestart);
        if (ctx.hasSecret) { lines.push(S.ruleSecret); }
        lines.push(S.ruleReport);
        lines.push(S.ruleNoAsk);

        lines.push('');
        lines.push(S.verify);
        lines.push(S.verifyTools);
        lines.push(S.verifyCall);

        // Behaviour that looks like a fault and is not. Without these an
        // agent "fixes" a working connection and leaves it broken.
        if (ctx.notes && ctx.notes.length) {
            lines.push('');
            lines.push(S.notesTitle);
            ctx.notes.forEach(function (note) { lines.push('- ' + note); });
        }

        el.textContent = lines.join('\n');
    };

    window.wppilotToggleAgentPrompt = function (prefix, btn) {
        var body = document.getElementById(prefix + '-agent-prompt-body');
        if (!body) { return; }
        var open = btn.getAttribute('aria-expanded') === 'true';
        body.hidden = open;
        body.style.display = open ? 'none' : '';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        btn.textContent = open ? S.show : S.hide;
    };

    window.wppilotCopyAgentPrompt = function (prefix, btn) {
        var el = document.getElementById(prefix + '-agent-prompt');
        if (!el) { return; }
        window.wppilotClipboardCopy(el.textContent).then(function () {
            var original = btn.textContent;
            btn.textContent = S.copied;
            setTimeout(function () { btn.textContent = original; }, 1500);
        });
    };
}());
