/* ==========================================================================
   Presence Platform — realtime feed client (TASK-016, ADR-026).
   TASK-017 — Calm Ledger row shape: initial avatars, event chips and
   "just now → N min ago" relative time for live arrivals (history rows
   keep their absolute time — only the arrival moment is knowable
   client-side, and SSR-first means no lying timestamps).

   TASK-020 — one client, two pages: the connection machinery (token,
   badge honesty, reconnect backoff) now serves BOTH the dashboards'
   live feed and the pairing desk. Any element carrying [data-realtime]
   is a boot node (the dashboards' #live-list, the desk's hidden boot
   div); the feed rendering below gates on #live-list existing, and
   pairing frames leave as a `realtime:pairing` CustomEvent the desk's
   script applies. Same wire, same honesty rules, no second client.

   No build step, no dependencies: the browser's native WebSocket API.

   Bootstrap contract (server-rendered JSON on [data-realtime]):
     { token, expires_at, port, max_rows, strings: {…} }

   Honesty rules:
     - badge states are truth: live / connecting / offline;
     - offline shows the fallback hint (reload to see the latest taps)
       instead of pretending the page is current — on the pairing desk
       the poll keeps the page honest on its own, so no hint node there;
     - reconnect backs off (1 s → 15 s) and re-mints the token via the
       session-authed /realtime/token endpoint when it is near expiry;
     - page hooks: `realtime:tap` (detail = the event row) and
       `realtime:pairing` (detail = {pending, last_pairing,
       recent_pairings}) CustomEvents so page scripts can react.

   Row DOM contract (mirrored by the SSR partial — one rendering
   path, one truth; the chip tone mapping lives in CSS only):
     li.live-row > .avatar + .live-main(.live-student + .live-context)
                                  + .live-side(.live-chip + .live-time)
   ========================================================================== */
(function () {
    'use strict';

    var bootNode = document.querySelector('[data-realtime]');
    if (!bootNode || typeof window.WebSocket !== 'function') { return; }

    var list = document.getElementById('live-list');

    var boot;
    try { boot = JSON.parse(bootNode.dataset.realtime || '{}'); } catch (e) { return; }
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

    function initialsOf(name) {
        var parts = String(name || '').trim().split(/\s+/);
        return ((parts[0] || '·').charAt(0) + (parts[1] ? parts[1].charAt(0) : '')).toUpperCase();
    }

    function rowFor(ev, fresh) {
        var li = document.createElement('li');
        li.className = 'live-row' + (fresh ? ' live-new' : '');
        li.dataset.eventId = String(ev.id);

        var avatar = document.createElement('span');
        avatar.className = 'avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = initialsOf(ev.student_name);

        var main = document.createElement('span');
        main.className = 'live-main';

        var student = document.createElement('span');
        student.className = 'live-student';
        student.textContent = ev.student_name || '';

        var context = document.createElement('span');
        context.className = 'live-context';
        context.textContent = (ev.class_name || '—') + ' · ' + (ev.reader_label || '—');

        main.appendChild(student);
        main.appendChild(context);

        var side = document.createElement('span');
        side.className = 'live-side';

        var chip = document.createElement('span');
        chip.className = 'live-chip';
        chip.setAttribute('data-event-type', ev.type || '');
        chip.textContent = ev.type || '';

        var time = document.createElement('span');
        time.className = 'live-time';
        time.textContent = ev.time || '';
        // absolute time survives as data, so a relative "just now" can
        // age out back to the wall clock without another round-trip
        li.dataset.absTime = ev.time || '';
        if (fresh) {
            li.dataset.arrived = String(Date.now());
            time.textContent = strings.rel_now || 'just now';
        }

        side.appendChild(chip);
        side.appendChild(time);

        li.appendChild(avatar);
        li.appendChild(main);
        li.appendChild(side);
        return li;
    }

    function clearEmpty() {
        var empty = document.getElementById('live-empty');
        if (empty) { empty.remove(); }
    }

    // The hello frame replaces the server-rendered rows with the same
    // shape the server rendered them from — one rendering path, one
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

    // Relative-time ticker: live-arrived rows age from "just now" to
    // "N min ago" for up to 10 minutes, then fall back to the absolute
    // wall time (kept in data-abs-time). History rows are untouched.
    function ageRelativeTimes() {
        if (!list) { return; }
        var now = Date.now();
        Array.prototype.forEach.call(list.children, function (li) {
            if (!li.dataset || !li.dataset.arrived) { return; }
            var ageSec = Math.floor((now - Number(li.dataset.arrived)) / 1000);
            var time = li.querySelector('.live-time');
            if (!time) { return; }
            if (ageSec < 45) {
                time.textContent = strings.rel_now || 'just now';
            } else if (ageSec < 600) {
                time.textContent = (strings.rel_min || ':n min ago').replace(':n', String(Math.floor(ageSec / 60)));
            } else {
                time.textContent = li.dataset.absTime || '';
                delete li.dataset.arrived;
            }
        });
    }
    setInterval(ageRelativeTimes, 15000);

    function refreshPageState(ev) {
        try {
            document.dispatchEvent(new CustomEvent('realtime:tap', { detail: ev }));
        } catch (e) { /* older browsers: the feed alone is enough */ }
    }

    // TASK-020 — the pairing desk's hook: the frame carries the exact
    // payload of GET /api/v1/admin/pairing/status; the desk script owns
    // what it means on the page.
    function refreshPairingState(payload) {
        if (!payload) { return; }
        try {
            document.dispatchEvent(new CustomEvent('realtime:pairing', { detail: payload }));
        } catch (e) { /* older browsers: the poll fallback still updates the desk */ }
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
                if (list) { renderHistory(data.events); }
                refreshPairingState(data.pairing);
            } else if (data.type === 'tap' && data.event) {
                if (list) { prependTap(data.event); }
                refreshPageState(data.event);
            } else if (data.type === 'pairing') {
                refreshPairingState(data);
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
