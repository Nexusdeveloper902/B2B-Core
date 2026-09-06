/* ==========================================================================
   Presence Platform — realtime feed client (TASK-016, ADR-026).

   No build step, no dependencies: the browser's native WebSocket API.
   Loaded by the dashboards after the server-rendered feed panel.

   Bootstrap contract (server-rendered JSON on #live-list[data-realtime]):
     { token, expires_at, port, max_rows, strings: {…} }

   Honesty rules:
     - badge states are truth: live / connecting / offline;
     - offline shows the fallback hint (reload to see the latest taps)
       instead of pretending the page is current;
     - reconnect backs off (1 s → 15 s) and re-mints the token via the
       session-authed /realtime/token endpoint when it is near expiry;
     - page hooks: a `realtime:tap` CustomEvent (detail = the event
       row) so page scripts can react (teacher attendance rows).
   ========================================================================== */
(function () {
    'use strict';

    var list = document.getElementById('live-list');
    if (!list || typeof window.WebSocket !== 'function') { return; }

    var boot;
    try { boot = JSON.parse(list.dataset.realtime || '{}'); } catch (e) { return; }
    if (!boot.token || !boot.port) { return; }

    var strings = boot.strings || {};
    var badge = document.getElementById('live-badge');
    var badgeText = document.getElementById('live-badge-text');
    var hint = document.getElementById('live-hint');
    var ws = null;
    var attempts = 0;

    function setState(name) {
        if (badge) { badge.dataset.state = name; }
        if (badgeText) { badgeText.textContent = strings['state_' + name] || name; }
    }

    function contextText(ev) {
        var parts = [ev.class_name || '—', ev.reader_label || '—'];
        return parts[0] + ' · ' + parts[1];
    }

    function rowFor(ev, fresh) {
        var li = document.createElement('li');
        li.className = 'live-row' + (fresh ? ' live-new' : '');
        li.dataset.eventId = String(ev.id);

        var time = document.createElement('span');
        time.className = 'live-time';
        time.textContent = ev.time || '';

        var student = document.createElement('span');
        student.className = 'live-student';
        student.textContent = ev.student_name || '';

        var context = document.createElement('span');
        context.className = 'live-context';
        context.appendChild(document.createTextNode(contextText(ev) + ' · '));
        var type = document.createElement('code');
        type.textContent = ev.type || '';
        context.appendChild(type);

        li.appendChild(time);
        li.appendChild(student);
        li.appendChild(context);
        return li;
    }

    function clearEmpty() {
        var empty = document.getElementById('live-empty');
        if (empty) { empty.remove(); }
    }

    // The hello frame replaces the server-rendered rows with the same
    // query the server rendered them from — one rendering path, one
    // truth (oldest first, capped).
    function renderHistory(events) {
        if (!events || !events.length) { return; }
        clearEmpty();
        while (list.firstChild) { list.removeChild(list.firstChild); }
        events.slice(-(boot.max_rows || 20)).forEach(function (ev) {
            list.appendChild(rowFor(ev, false));
        });
    }

    function prependTap(ev) {
        clearEmpty();
        list.insertBefore(rowFor(ev, true), list.firstChild);
        while (list.children.length > (boot.max_rows || 20)) {
            list.removeChild(list.lastChild);
        }
    }

    function refreshPageState(ev) {
        try {
            document.dispatchEvent(new CustomEvent('realtime:tap', { detail: ev }));
        } catch (e) { /* older browsers: the feed alone is enough */ }
    }

    function mintToken() {
        return fetch('/realtime/token', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.token) {
                    boot.token = data.token;
                    boot.expires_at = data.expires_at;
                }
            });
    }

    function scheduleReconnect() {
        var delay = Math.min(15000, 1000 * Math.pow(2, attempts++));
        var expiring = ((boot.expires_at || 0) * 1000) - Date.now() < 60000;
        var ready = expiring ? mintToken() : Promise.resolve();
        ready.then(function () { setTimeout(connect, delay); });
    }

    function connect() {
        setState('connecting');
        var url = 'ws://' + window.location.hostname + ':' + boot.port +
            '/app?token=' + encodeURIComponent(boot.token);
        try {
            ws = new WebSocket(url);
        } catch (e) {
            scheduleReconnect();
            return;
        }

        ws.onopen = function () {
            attempts = 0;
            setState('live');
            if (hint) { hint.classList.add('hidden'); }
        };

        ws.onmessage = function (message) {
            var data;
            try { data = JSON.parse(message.data); } catch (e) { return; }
            if (data.type === 'hello') {
                renderHistory(data.events);
            } else if (data.type === 'tap' && data.event) {
                prependTap(data.event);
                refreshPageState(data.event);
            }
        };

        ws.onclose = function () {
            setState('offline');
            if (hint) { hint.classList.remove('hidden'); }
            scheduleReconnect();
        };

        ws.onerror = function () {
            try { ws.close(); } catch (e) { /* already dying */ }
        };
    }

    if (hint) { hint.classList.add('hidden'); }
    connect();
})();
