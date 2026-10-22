/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Troubleshoot panel: run the connection checks, show the report, the symptom
 * picker and the Auth Client ID generator. Shared by every panel on the page
 * through event delegation on the data-wppilot-ts attributes.
 *
 * Data: window.wppilotTroubleshoot = { nonce, runUrl, mintUrl, labels }.
 */
(function () {
    var config = window.wppilotTroubleshoot || {};
    var nonce = config.nonce;
    var runUrl = config.runUrl;
    var mintUrl = config.mintUrl;
    var labels = config.labels;
    var glyphs = { ok: '✓', warning: '!', fail: '✕', skipped: '–', info: 'i' };

    // The panel now lives on its own Troubleshoot page, not only inside the Connect page, so it
    // must provide its own clipboard helper. navigator.clipboard needs a secure context (HTTPS
    // or localhost); on plain HTTP fall back to a hidden textarea + execCommand('copy').
    if (!window.wppilotClipboardCopy) {
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
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('copy command was rejected'));
            });
        };
    }

    function post(url, body) {
        return window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
    }

    function statusKey(status) {
        return Object.prototype.hasOwnProperty.call(glyphs, status) ? status : 'skipped';
    }

    function renderSkeleton(list) {
        list.textContent = '';
        for (var i = 0; i < 3; i++) {
            var li = document.createElement('li');
            li.className = 'wppilot-ts-skeleton';
            list.appendChild(li);
        }
    }

    function renderSummary(summary, counts) {
        summary.textContent = '';
        ['fail', 'warning', 'info'].filter(function (key) { return counts[key] > 0; }).forEach(function (key) {
            var chip = document.createElement('span');
            chip.className = 'wppilot-ts-chip is-' + key;
            chip.textContent = counts[key] + ' ' + labels[key];
            summary.appendChild(chip);
        });
        summary.hidden = false;
    }

    function buildRow(c, i) {
        var status = statusKey(c.status);
        var li = document.createElement('li');
        li.className = 'wppilot-ts-row is-' + status;
        li.style.animationDelay = (i * 45) + 'ms';

        var dot = document.createElement('span');
        dot.className = 'wppilot-ts-dot';
        dot.textContent = glyphs[status];
        dot.setAttribute('aria-label', labels[status] || status);
        li.appendChild(dot);

        var body = document.createElement('div');
        body.className = 'wppilot-ts-row-body';
        var title = document.createElement('div');
        title.className = 'wppilot-ts-row-title';
        title.textContent = c.label;
        body.appendChild(title);
        var msg = document.createElement('div');
        msg.className = 'wppilot-ts-row-msg';
        msg.textContent = c.message;
        body.appendChild(msg);

        if (c.remedy) {
            var fix = document.createElement('div');
            fix.className = 'wppilot-ts-remedy';
            fix.textContent = '→ ' + c.remedy;
            // A remedy that lives inside a symptom branch gets a button that opens it,
            // so nobody has to figure out which entry of the picker hides the fix.
            if (c.action) {
                var open = document.createElement('button');
                open.type = 'button';
                open.className = 'button button-small';
                open.textContent = labels.openFix;
                open.setAttribute('data-wppilot-ts-open', c.action);
                fix.appendChild(document.createElement('br'));
                fix.appendChild(open);
            }
            // A ready-to-send text (e.g. the message for the hosting support) rendered
            // as a copyable block right inside the remedy.
            if (c.copy) {
                var copyWrap = document.createElement('div');
                copyWrap.className = 'wppilot-ts-copy';
                var copyText = document.createElement('pre');
                copyText.className = 'wppilot-ts-copy-text';
                copyText.textContent = c.copy;
                copyWrap.appendChild(copyText);
                var copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'button button-small';
                copyBtn.textContent = labels.copy;
                copyBtn.setAttribute('data-wppilot-ts', 'remedy-copy');
                copyWrap.appendChild(copyBtn);
                fix.appendChild(copyWrap);
            }
            body.appendChild(fix);
        }

        li.appendChild(body);
        return li;
    }

    function renderCopyReport(container, report) {
        container.textContent = '';
        if (!report) { container.hidden = true; return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button';
        btn.textContent = labels.copyReport;
        btn.setAttribute('data-wppilot-ts', 'report-copy');
        container.setAttribute('data-report', report);
        container.appendChild(btn);
        container.hidden = false;
    }

    // Outcome-driven: a clean run (all passed, nothing in front) shows one calm line; a run that
    // passed but detected a security/edge layer shows a caveat plus that row; a run with any
    // fail/warning shows only the problem rows. A healthy run stays quiet instead of listing
    // every green check, and the copy-report button appears whenever there is something to send.
    function renderReport(els, data) {
        var checks = data.checks || [];
        els.report.textContent = '';
        els.summary.hidden = true;
        els.summary.textContent = '';
        els.clean.hidden = true;
        els.clean.className = 'wppilot-ts-clean';
        els.copyReport.hidden = true;
        els.copyReport.textContent = '';

        var problems = checks.filter(function (c) { return c.status === 'fail' || c.status === 'warning'; });
        var infos = checks.filter(function (c) { return c.status === 'info'; });

        if (problems.length === 0 && infos.length === 0) {
            els.clean.textContent = glyphs.ok + '  ' + labels.clean;
            els.clean.hidden = false;
            return;
        }

        if (problems.length === 0) {
            // Passed, but a security/edge layer is in front: a heads-up (amber), not a green pass.
            els.clean.className = 'wppilot-ts-clean is-caveat';
            els.clean.textContent = labels.caveat;
            els.clean.hidden = false;
            infos.forEach(function (c, i) { els.report.appendChild(buildRow(c, i)); });
            renderCopyReport(els.copyReport, data.report);
            return;
        }

        problems.concat(infos).forEach(function (c, i) { els.report.appendChild(buildRow(c, i)); });
        var counts = { fail: 0, warning: 0, info: 0 };
        checks.forEach(function (c) { if (counts[c.status] !== undefined) { counts[c.status] += 1; } });
        renderSummary(els.summary, counts);
        renderCopyReport(els.copyReport, data.report);
    }

    // Clear a previous run's output. Used when the method changes, since the results, report,
    // and symptom guidance all belonged to the old method.
    function resetPanel(panel) {
        var report = panel.querySelector('[data-wppilot-ts="report"]');
        var summary = panel.querySelector('[data-wppilot-ts="summary"]');
        var clean = panel.querySelector('[data-wppilot-ts="clean"]');
        var copyReport = panel.querySelector('[data-wppilot-ts="copy-report"]');
        var symptoms = panel.querySelector('[data-wppilot-ts="symptoms"]');
        var symptomSelect = panel.querySelector('[data-wppilot-ts="symptom"]');
        if (report) { report.textContent = ''; }
        if (summary) { summary.hidden = true; summary.textContent = ''; }
        if (clean) { clean.hidden = true; clean.className = 'wppilot-ts-clean'; }
        if (copyReport) { copyReport.hidden = true; copyReport.textContent = ''; }
        if (symptoms) { symptoms.hidden = true; }
        if (symptomSelect) { symptomSelect.value = ''; }
        panel.querySelectorAll('[data-wppilot-ts-branch]').forEach(function (b) { b.hidden = true; });
    }

    function applyMethod(panel) {
        var method = panel.getAttribute('data-wppilot-ts-method') || '';
        var select = panel.querySelector('[data-wppilot-ts="symptom"]');
        if (!select) { return; }
        var selectedHidden = false;
        select.querySelectorAll('option[data-method]').forEach(function (option) {
            var own = option.getAttribute('data-method') || '';
            var show = method === '' || own === '' || own === method;
            option.hidden = !show;
            if (!show && option.selected) { selectedHidden = true; }
        });
        if (selectedHidden) {
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // The Connect page calls this when its method chooser changes, so the panel's checks
    // and symptom list follow the method the user is actually setting up.
    window.wppilotTsSetMethod = function (method) {
        document.querySelectorAll('.wppilot-troubleshoot').forEach(function (panel) {
            var known = method === 'oauth' || method === 'password' || method === 'token';
        panel.setAttribute('data-wppilot-ts-method', known ? method : '');
            applyMethod(panel);
        });
    };

    document.querySelectorAll('.wppilot-troubleshoot').forEach(applyMethod);

    document.addEventListener('click', function (e) {
        var target = e.target;
        if (!(target instanceof Element)) { return; }
        var panel = target.closest('.wppilot-troubleshoot');
        if (!panel) { return; }

        var openSymptom = target.getAttribute('data-wppilot-ts-open');
        if (openSymptom) {
            var symptomSelect = panel.querySelector('[data-wppilot-ts="symptom"]');
            symptomSelect.value = openSymptom;
            symptomSelect.dispatchEvent(new Event('change', { bubbles: true }));
            var branch = panel.querySelector('[data-wppilot-ts-branch="' + openSymptom + '"]');
            if (branch) { branch.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
            return;
        }

        var action = target.getAttribute('data-wppilot-ts');
        if (!action) { return; }

        if (action === 'run') {
            var els = {
                report: panel.querySelector('[data-wppilot-ts="report"]'),
                summary: panel.querySelector('[data-wppilot-ts="summary"]'),
                clean: panel.querySelector('[data-wppilot-ts="clean"]'),
                copyReport: panel.querySelector('[data-wppilot-ts="copy-report"]')
            };
            var method = panel.getAttribute('data-wppilot-ts-method') || '';
            els.summary.hidden = true;
            els.clean.hidden = true;
            els.copyReport.hidden = true;
            renderSkeleton(els.report);
            target.disabled = true;
            // Lock the method picker while a run is in flight, so it cannot change under a
            // response that belongs to the method the run started with.
            var methodPick = panel.querySelector('[data-wppilot-ts="method-pick"]');
            if (methodPick) { methodPick.disabled = true; }
            post(runUrl, method === '' ? {} : { method: method }).then(function (r) {
                target.disabled = false;
                if (methodPick) { methodPick.disabled = false; }
                if (!r.ok || !r.json.checks) { els.report.textContent = labels.error; return; }
                renderReport(els, r.json);
                // The symptom guide points at "the checks above", so it only appears once a run
                // has produced them.
                var symptoms = panel.querySelector('[data-wppilot-ts="symptoms"]');
                if (symptoms) { symptoms.hidden = false; }
            }).catch(function () {
                target.disabled = false;
                if (methodPick) { methodPick.disabled = false; }
                els.report.textContent = labels.error;
            });
        }

        if (action === 'mint') {
            var client = panel.querySelector('[data-wppilot-ts="client"]').value;
            var out = panel.querySelector('[data-wppilot-ts="mint-result"]');
            var err = panel.querySelector('[data-wppilot-ts="mint-error"]');
            target.disabled = true;
            post(mintUrl, { client: client }).then(function (r) {
                target.disabled = false;
                if (!r.ok || !r.json.client_id) {
                    err.textContent = r.json && r.json.message ? r.json.message : labels.error;
                    err.hidden = false;
                    out.hidden = true;
                    return;
                }
                err.hidden = true;
                panel.querySelector('[data-wppilot-ts="mint-id"]').textContent = r.json.client_id;
                panel.querySelector('[data-wppilot-ts="mint-hint"]').textContent = r.json.field_hint;
                out.hidden = false;
            }).catch(function () {
                target.disabled = false;
                err.textContent = labels.error;
                err.hidden = false;
            });
        }

        if (action === 'remedy-copy') {
            var block = target.closest('.wppilot-ts-copy').querySelector('.wppilot-ts-copy-text');
            window.wppilotClipboardCopy(block.textContent).then(function () {
                var origLabel = target.textContent;
                target.textContent = labels.copied;
                setTimeout(function () { target.textContent = origLabel; }, 1500);
            });
        }

        if (action === 'report-copy') {
            var reportEl = panel.querySelector('[data-wppilot-ts="copy-report"]');
            window.wppilotClipboardCopy(reportEl.getAttribute('data-report') || '').then(function () {
                var orig = target.textContent;
                target.textContent = labels.reportCopied;
                setTimeout(function () { target.textContent = orig; }, 1500);
            });
        }

        if (action === 'mint-copy') {
            var id = panel.querySelector('[data-wppilot-ts="mint-id"]').textContent;
            window.wppilotClipboardCopy(id).then(function () {
                var orig = target.textContent;
                target.textContent = labels.copied;
                setTimeout(function () { target.textContent = orig; }, 1500);
            });
        }
    });

    document.addEventListener('change', function (e) {
        var target = e.target;
        if (!(target instanceof Element)) { return; }
        var tsAttr = target.getAttribute('data-wppilot-ts');
        if (tsAttr === 'method-pick') {
            if (window.wppilotTsSetMethod) { window.wppilotTsSetMethod(target.value); }
            var mpPanel = target.closest('.wppilot-troubleshoot');
            if (mpPanel) { resetPanel(mpPanel); }
            return;
        }
        if (tsAttr !== 'symptom') { return; }
        var panel = target.closest('.wppilot-troubleshoot');
        panel.querySelectorAll('[data-wppilot-ts-branch]').forEach(function (branch) {
            branch.hidden = branch.getAttribute('data-wppilot-ts-branch') !== target.value;
        });
    });
})();
