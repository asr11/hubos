/* OS HUB — global timer widget (M3). Vanilla JS, no deps.
 * The server is the source of truth: the page renders the elapsed seconds,
 * this script only ticks locally and re-syncs with GET /timer every 30s and
 * when the tab becomes visible again. Also filters task <select>s by client. */
(function () {
    'use strict';

    function fmt(total) {
        total = Math.max(0, Math.floor(total));
        var h = Math.floor(total / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
        return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    var el = document.getElementById('timer-elapsed');
    var box = document.getElementById('timer-box');
    if (el && box) {
        var base = parseInt(el.getAttribute('data-elapsed') || '0', 10) || 0;
        var running = el.getAttribute('data-running') === '1';
        var t0 = Date.now();
        var render = function () {
            el.textContent = fmt(running ? base + (Date.now() - t0) / 1000 : base);
        };
        render();
        setInterval(render, 1000);

        var sync = function () {
            fetch(box.getAttribute('data-state-url') || '/timer', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) { return; }
                    if (!d.active) { window.location.reload(); return; } // stopped elsewhere
                    if (d.running !== running) { window.location.reload(); return; }
                    base = d.elapsed; t0 = Date.now(); render();
                })
                .catch(function () { /* offline: keep ticking locally */ });
        };
        setInterval(sync, 30000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { sync(); } });
    }

    // Smart two-way Client <-> Task <select> binding with <optgroup> grouping and compact labels.
    var forms = document.querySelectorAll('form');
    Array.prototype.forEach.call(forms, function (form) {
        var client = form.querySelector('[data-role="client-select"]');
        var task = form.querySelector('[data-role="task-select"]');
        if (!client || !task) { return; }
        var isHeaderTimer = form.id === 'timer-start-form';
        var maxLen = isHeaderTimer ? 46 : 75;
        var placeholderText = task.options.length > 0 ? task.options[0].textContent : 'משימה (רשות)';
        var masterTasks = [];
        Array.prototype.forEach.call(task.options, function (o) {
            var cid = o.getAttribute('data-client');
            if (!cid || !o.value) { return; }
            var full = (o.textContent || '').trim();
            var shortLabel = full.length > maxLen ? full.slice(0, maxLen - 1).trim() + '…' : full;
            masterTasks.push({
                value: o.value,
                title: full,
                label: shortLabel,
                clientId: cid,
                clientName: o.getAttribute('data-client-name') || 'משימות פתוחות'
            });
        });

        var rebuild = function (preserveTaskVal) {
            var chosenClient = client.value;
            var currentTask = preserveTaskVal !== undefined ? preserveTaskVal : task.value;
            while (task.firstChild) { task.removeChild(task.firstChild); }

            var matching = chosenClient
                ? masterTasks.filter(function (t) { return t.clientId === chosenClient; })
                : masterTasks;

            var firstOpt = document.createElement('option');
            firstOpt.value = '';
            firstOpt.textContent = (chosenClient && matching.length === 0)
                ? 'ללא משימות פתוחות (רשות)'
                : placeholderText;
            task.appendChild(firstOpt);

            if (chosenClient) {
                matching.forEach(function (t) {
                    var opt = document.createElement('option');
                    opt.value = t.value;
                    opt.setAttribute('data-client', t.clientId);
                    opt.textContent = t.label;
                    opt.title = t.title;
                    if (t.value === currentTask) { opt.selected = true; }
                    task.appendChild(opt);
                });
            } else {
                var groups = {};
                var order = [];
                matching.forEach(function (t) {
                    if (!groups[t.clientId]) {
                        groups[t.clientId] = { label: t.clientName, items: [] };
                        order.push(t.clientId);
                    }
                    groups[t.clientId].items.push(t);
                });
                order.forEach(function (cid) {
                    var grp = document.createElement('optgroup');
                    grp.label = groups[cid].label;
                    groups[cid].items.forEach(function (t) {
                        var opt = document.createElement('option');
                        opt.value = t.value;
                        opt.setAttribute('data-client', t.clientId);
                        opt.textContent = t.label;
                        opt.title = t.title;
                        if (t.value === currentTask) { opt.selected = true; }
                        grp.appendChild(opt);
                    });
                    task.appendChild(grp);
                });
            }
        };

        client.addEventListener('change', function () {
            rebuild('');
        });
        task.addEventListener('change', function () {
            var picked = task.value;
            if (!picked) { return; }
            for (var i = 0; i < masterTasks.length; i++) {
                if (masterTasks[i].value === picked) {
                    if (client.value !== masterTasks[i].clientId) {
                        client.value = masterTasks[i].clientId;
                        rebuild(picked);
                    }
                    break;
                }
            }
        });
        rebuild(task.value);
    });

    // CSP-compliant UI interactions (toggles, close buttons, quick task presets).
    document.addEventListener('click', function (ev) {
        var toggleBtn = ev.target.closest('[data-toggle-target]');
        if (toggleBtn) {
            var target = document.getElementById(toggleBtn.getAttribute('data-toggle-target'));
            if (target) {
                if (target.tagName === 'DETAILS') {
                    target.toggleAttribute('open');
                    if (target.hasAttribute('open')) {
                        var focusId = toggleBtn.getAttribute('data-focus-id');
                        if (focusId) {
                            var focusEl = document.getElementById(focusId);
                            if (focusEl) { focusEl.focus(); }
                        }
                    }
                } else {
                    var willShow = target.classList.contains('is-hidden');
                    target.classList.toggle('is-hidden', !willShow);
                    if (willShow) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            }
            return;
        }

        var closeBtn = ev.target.closest('[data-close-target]');
        if (closeBtn) {
            var closeEl = document.getElementById(closeBtn.getAttribute('data-close-target'));
            if (closeEl) {
                if (closeEl.tagName === 'DETAILS') {
                    closeEl.removeAttribute('open');
                } else {
                    closeEl.classList.add('is-hidden');
                }
            }
            return;
        }

        var presetBtn = ev.target.closest('[data-preset-title]');
        if (presetBtn) {
            var pForm = presetBtn.closest('form');
            if (!pForm) { return; }
            var tInput = pForm.querySelector('input[name="title"]');
            var dInput = pForm.querySelector('textarea[name="description"]');
            var pSelect = pForm.querySelector('select[name="priority"]');
            if (tInput) { tInput.value = presetBtn.getAttribute('data-preset-title') || ''; }
            if (dInput) { dInput.value = presetBtn.getAttribute('data-preset-desc') || ''; }
            if (pSelect && presetBtn.getAttribute('data-preset-priority')) {
                pSelect.value = presetBtn.getAttribute('data-preset-priority');
            }
            if (tInput) { tInput.focus(); }
        }
    });
}());

