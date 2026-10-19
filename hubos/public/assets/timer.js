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

    // Task <select> follows the chosen client (data-role="client-select" / "task-select" in the same form).
    var forms = document.querySelectorAll('form');
    Array.prototype.forEach.call(forms, function (form) {
        var client = form.querySelector('[data-role="client-select"]');
        var task = form.querySelector('[data-role="task-select"]');
        if (!client || !task) { return; }
        var apply = function () {
            Array.prototype.forEach.call(task.options, function (o) {
                var c = o.getAttribute('data-client');
                var show = !c || !client.value || c === client.value;
                o.hidden = !show;
                o.disabled = !show;
                if (!show && o.selected) { task.value = ''; }
            });
        };
        client.addEventListener('change', apply);
        apply();
    });
}());
