{{--
    TASK-026 — mockup "Pair Cards — Pairing Desk": roster with client-side
    search, live status panel with NFC pulse art + draining countdown,
    recently-paired ledger. The ENTIRE realtime/poll/arm script below is
    the TASK-020/023/024 machine, byte-identical in behavior — only the
    markup around it changed. TASK-027 — each paired card in the roster
    gains its own Unpair button (gap D1's web half, on top of the
    DELETE /api/v1/admin/cards/{card} endpoint). Remaining mockup-only
    parts are documented as gaps (docs/FRONTEND.md): status state
    reference matrix, reader terminal telemetry, cryptographic footer
    (gaps #D2-D4).
--}}
@extends('layouts.app')
@use('Illuminate\Support\Js', 'Js')

@section('title', __('app.pairing_desk'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.pairing_desk') }}</span>
    <h1>{{ __('app.pairing_desk') }}</h1>
    <p class="lede-sub">{{ __('app.pairing_desk_intro') }}</p>
</div>

<section class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Arming table: one click per student (replaces the curl+PAT dance) --}}
    <x-panel :label="__('app.students')" rule>
        <div class="filterbar filterbar--flush">
            <div class="searchbox">
                <span class="material-symbols-outlined is-18" aria-hidden="true">search</span>
                <input type="search" id="roster-search" aria-label="{{ __('app.search_students') }}"
                       placeholder="{{ __('app.search_students') }}" autocomplete="off">
            </div>
        </div>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack data-roster>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.student') }}</th>
                    <th scope="col">{{ __('app.class') }}</th>
                    <th scope="col">{{ __('app.current_card') }}</th>
                    <th scope="col">{{ __('app.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($students as $student)
                    <tr data-student-row="{{ $student->id }}" data-search="{{ mb_strtolower($student->name) }}">
                        <td data-label="{{ __('app.student') }}">{{ $student->name }}</td>
                        <td data-label="{{ __('app.class') }}">{{ $student->schoolClass?->name ?? '—' }}</td>
                        <td data-label="{{ __('app.current_card') }}" data-card-cell="{{ $student->id }}">
                            @forelse($student->cards as $card)
                                {{-- TASK-027 — the per-card unpair surface: one
                                      chip per credential, each with its own
                                      server-backed Unpair action (D1). --}}
                                <span class="card-chip" data-card-chip="{{ $card->id }}">
                                    <code>{{ $card->credential_uid }}</code>
                                    <button type="button" class="btn btn-quiet btn-small unpair-btn"
                                            data-unpair="{{ $card->id }}"
                                            data-uid="{{ $card->credential_uid }}"
                                            data-name="{{ $student->name }}"
                                            data-student="{{ $student->id }}"
                                            aria-label="{{ __('app.unpair') }} {{ $card->credential_uid }}">
                                        {{ __('app.unpair') }}
                                    </button>
                                </span>
                            @empty
                                <span class="muted">{{ __('app.no_card') }}</span>
                            @endforelse
                        </td>
                        <td data-label="{{ __('app.action') }}">
                            <button type="button" class="btn btn-primary btn-small arm-btn"
                                    data-student="{{ $student->id }}"
                                    data-name="{{ $student->name }}">
                                {{ __('app.pairing_arm') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">—</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    {{-- Live status: armed window countdown + last result. TASK-020 —
         now a REALTIME panel: the badge + [data-realtime] boot feed the
         same realtime.js the dashboards use; pairing frames update the
         desk the instant a card is paired (arm/consume/reject), and the
         poll below stays as the honest fallback when the socket is down.
         TASK-026 — the mockup's NFC concentric-wave art rides under the
         status box (pure decoration, aria-hidden). --}}
    <x-panel :label="__('app.pairing_status')" rule class="live-panel">
        <div class="live-head">
            <span class="live-panel-sub muted small">{{ __('app.pairing_window') }}</span>
            <span id="live-badge" class="live-badge" data-state="connecting" role="status">
                <span class="live-dot" aria-hidden="true"></span><span id="live-badge-text">{{ __('app.live_state_connecting') }}</span>
            </span>
        </div>
        <div id="pairing-realtime" hidden data-realtime="{{ json_encode([
            'token' => $realtimeToken,
            'expires_at' => $realtimeTokenExpires,
            'port' => (int) config('realtime.port'),
            'max_rows' => (int) config('realtime.history_limit'),
            'strings' => [
                'state_live' => __('app.live_state_live'),
                'state_connecting' => __('app.live_state_connecting'),
                'state_offline' => __('app.live_state_offline'),
            ],
        ]) }}"></div>
        <div class="pulse" aria-hidden="true">
            <span class="ring"></span>
            <span class="ring"></span>
            <span class="ring"></span>
            <span class="pulse-core"><span class="material-symbols-outlined is-20">contactless</span></span>
        </div>
        <div id="pairing-state" class="nl-answer {{ $activeSession ? 'answer-ok' : 'hidden' }}"
             aria-live="polite" data-initially-armed="{{ $activeSession ? '1' : '0' }}"
             @if($activeSession) data-student-name="{{ $activeSession->student?->name }}" @endif
             @if($activeSession) data-seconds-left="{{ $activeSecondsLeft }}" @endif
             @if($activeRejectionNote) data-rejection-note="{{ $activeRejectionNote }}" @endif>
            @if($activeSession)
                {{ __('app.pairing_armed_for', ['name' => $activeSession->student?->name]) }} —
                {{ __('app.pairing_seconds_left', ['s' => $activeSecondsLeft]) }}
                {{ __('app.pairing_go_tap') }}
                @if($activeRejectionNote)
                    {{ $activeRejectionNote }}
                @endif
            @endif
        </div>
        {{-- TASK-017 — the window as a draining bar: urgency at a glance.
             SIBLING of #pairing-state (the script rewrites that box's
             textContent — a child would not survive it). TASK-021 — the
             status box + bar follow the live-panel full-bleed grammar
             (tone strip + edge-to-edge meter, app.css scopes it). --}}
        <div id="pairing-countdown" class="countdown {{ $activeSession ? '' : 'hidden' }}"
             role="progressbar" aria-label="{{ __('app.pairing_window') }}"
             data-total="{{ $pairingWindowSeconds }}"
             @if(! $activeSession) aria-hidden="true" @endif>
            <div class="countdown-fill"></div>
        </div>
        @if(! $activeSession)
            <p class="live-empty" id="pairing-idle">{{ __('app.pairing_no_session') }}</p>
        @endif
    </x-panel>
</section>

<section class="stack" data-reveal>
    {{-- History: exact card->student links this platform made --}}
    <x-panel :label="__('app.pairing_recent')" rule>
        <div class="ledger-wrap" id="recent-wrap">
            <table class="ledger-table" data-stack>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.pairing_uid') }}</th>
                    <th scope="col">{{ __('app.student') }}</th>
                    <th scope="col">{{ __('app.pairing_paired_at') }}</th>
                    <th scope="col">{{ __('app.reader_label') }}</th>
                </tr>
                </thead>
                <tbody id="recent-body">
                @forelse($recentPairings as $pairing)
                    <tr>
                        <td data-label="{{ __('app.pairing_uid') }}"><code>{{ $pairing->card?->credential_uid }}</code></td>
                        <td data-label="{{ __('app.student') }}">{{ $pairing->student?->name }}</td>
                        <td class="num" data-label="{{ __('app.pairing_paired_at') }}">{{ $pairing->consumed_at?->format('Y-m-d H:i') }}</td>
                        <td data-label="{{ __('app.reader_label') }}">{{ $pairing->reader?->label ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted" id="recent-empty">{{ __('app.pairing_none_yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</section>

<x-confirm-modal id="unpair-confirm" />

<script src="{{ asset('js/realtime.js') }}"></script>
<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var stateBox = document.getElementById('pairing-state');
        var idleNote = document.getElementById('pairing-idle');
        var recentBody = document.getElementById('recent-body');
        var pollTimer = null;
        // TASK-014 — every JSON literal in this script is emitted through a
        // raw (unescaped) echo: Blade's default escaped echo turns the
        // quotes into HTML entities and kills the whole script — the
        // original lastSeenUid line did exactly that after the FIRST
        // completed pairing (dead buttons on every reload until the
        // history was empty again). json_encode escapes slashes, so the
        // output cannot break out of the script tag.
        var lastSeenUid = {!! Js::from($lastCardUid) !!};
        var armed = stateBox.dataset.initiallyArmed === '1';
        var secondsLeft = parseInt(stateBox.dataset.secondsLeft || '0', 10);
        var armBtns = Array.prototype.slice.call(document.querySelectorAll('.arm-btn'));

        // TASK-027 — per-card unpair (gap D1, GUI half): each card chip in
        // the roster carries an Unpair button backed by DELETE
        // /api/v1/admin/cards/{card}. The confirm copy and the empty-cell
        // text come from the same lang files the server renders with.
        var unpairBtns = Array.prototype.slice.call(document.querySelectorAll('.unpair-btn'));
        var UNPAIRED_TEXT = {!! Js::from(__('app.unpaired')) !!};
        var UNPAIR_LABEL = {!! Js::from(__('app.unpair')) !!};
        var NO_CARD_TEXT = {!! Js::from(__('app.no_card')) !!};

        // TASK-017 — the armed window as a draining progress bar.
        var countdown = document.getElementById('pairing-countdown');
        var countdownFill = countdown ? countdown.querySelector('.countdown-fill') : null;
        var WINDOW_TOTAL = countdown ? parseInt(countdown.dataset.total || '45', 10) : 45;

        function showCountdown(show) {
            if (!countdown) { return; }
            countdown.classList.toggle('hidden', !show);
            countdown.setAttribute('aria-hidden', show ? 'false' : 'true');
            if (show) { renderCountdown(); }
        }

        function renderCountdown() {
            if (!countdownFill) { return; }
            var pct = Math.max(0, Math.min(100, Math.round((secondsLeft / WINDOW_TOTAL) * 100)));
            countdownFill.style.width = pct + '%';
            countdown.classList.toggle('is-low', secondsLeft <= 10);
        }

        // TASK-014 — localized templates for the rejection note (session
        // locale, same convention as every other desk string).
        var REJECTED_TPL = {!! Js::from(__('app.pairing_rejected', ['uid' => ':UID:', 'reason' => ':REASON:'])) !!};
        var REASON_TEXT = {
            'already_paired': {!! Js::from(__('app.pairing_reason_already_paired')) !!}
        };
        var rejectionNote = stateBox.dataset.rejectionNote || null;

        var ARMED_TPL = {!! Js::from(__('app.pairing_armed_for', ['name' => ':NAME:']) . ' — ' . __('app.pairing_seconds_left', ['s' => ':S:']) . ' ' . __('app.pairing_go_tap')) !!};
        var EXPIRED_TEXT = {!! Js::from(__('app.pairing_expired')) !!};
        var SUCCESS_TPL = {!! Js::from(__('app.pairing_success', ['uid' => ':UID:', 'name' => ':NAME:'])) !!};

        var ACTIVE_MS = 2000;   // armed window: live countdown
        var IDLE_MS = 15000;    // idle: quiet watch (cross-tab arm / success)

        function postJson(url) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                }
            }).then(function (r) {
                return r.json().then(function (data) {
                    return {ok: r.ok, status: r.status, data: data};
                });
            });
        }

        function getJson(url) {
            return fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}
            }).then(function (r) {
                return r.json().then(function (data) {
                    return {ok: r.ok, status: r.status, data: data};
                });
            });
        }

        // TASK-027 — same-origin, session-authed DELETE with the CSRF
        // header (mirrors postJson's contract for the destructive verb).
        function deleteJson(url) {
            return fetch(url, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                }
            }).then(function (r) {
                return r.json().then(function (data) {
                    return {ok: r.ok, status: r.status, data: data};
                });
            });
        }

        function setState(text, ok) {
            if (idleNote) idleNote.remove();
            stateBox.classList.remove('hidden');
            stateBox.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
            stateBox.textContent = text;
        }

        function armedLine() {
            var line = ARMED_TPL.replace(':NAME:', stateBox.dataset.studentName || '')
                .replace(':S:', secondsLeft);
            // The armed window is still LIVE after a rejected tap — the note
            // rides along with the countdown instead of replacing it.
            if (rejectionNote) { line += ' ' + rejectionNote; }
            return line;
        }

        function noteFromFeed(rejection) {
            if (!rejection || !rejection.card_uid) return null;
            var reason = REASON_TEXT[rejection.reason] || rejection.reason;
            return REJECTED_TPL.replace(':UID:', rejection.card_uid).replace(':REASON:', reason);
        }

        function renderRecent(list) {
            if (!recentBody || !list) return;
            recentBody.innerHTML = '';
            list.forEach(function (p) {
                var tr = document.createElement('tr');
                [p.card_uid, p.student_name, (p.paired_at || '').replace('T', ' ').slice(0, 16), p.reader_label || '—']
                    .forEach(function (value, i) {
                        var td = document.createElement('td');
                        td.textContent = value === null || value === undefined ? '—' : value;
                        if (i === 0) { var code = document.createElement('code'); code.textContent = td.textContent; td.textContent = ''; td.appendChild(code); }
                        tr.appendChild(td);
                    });
                recentBody.appendChild(tr);
            });
        }

        // TASK-023 — the student row's card cell follows backend truth:
        // called ONLY from the backend-confirmed success branch below
        // (WS pairing frame, hello reconcile, or status poll — all carry
        // the same server payload), never from a client guess. textContent
        // only, so a UID can never inject markup; already-shown UIDs are
        // left alone so re-applied frames stay idempotent.
        function renderStudentCard(last) {
            if (!last || last.student_id === undefined || last.student_id === null || !last.card_uid) { return; }
            var row = document.querySelector('tr[data-student-row="' + last.student_id + '"]');
            if (!row) { return; }
            var cell = row.querySelector('[data-card-cell]');
            if (!cell) { return; }
            var uid = String(last.card_uid);
            var codes = cell.querySelectorAll('code');
            for (var i = 0; i < codes.length; i++) {
                if (codes[i].textContent === uid) { return; }
            }
            var code = document.createElement('code');
            code.textContent = uid;
            if (codes.length === 0) { cell.textContent = ''; cell.appendChild(code); }
            else { cell.appendChild(document.createTextNode(' ')); cell.appendChild(code); }
        }

        function setPollInterval(ms) {
            if (pollTimer) { clearInterval(pollTimer); }
            pollTimer = setInterval(poll, ms);
        }

        function tick() {
            if (!armed) return;
            if (secondsLeft > 0) {
                secondsLeft -= 1;
                renderCountdown();
                if (secondsLeft > 0) {
                    setState(armedLine(), true);
                    return;
                }
            }
            // TASK-020 — the window drained on our own clock: finalize
            // locally instead of lying at "0 s left" until the next
            // poll. A live frame (WS or poll) that disagrees simply
            // re-arms the UI — applyStatus is idempotent.
            setState(EXPIRED_TEXT, false);
            armed = false;
            rejectionNote = null;
            showCountdown(false);
            setPollInterval(IDLE_MS);
        }

        // One state applier, three sources: the poll, a `realtime:pairing`
        // frame (TASK-020 — same payload as the status endpoint, so the
        // WebSocket path and the REST path can never disagree), and the
        // hello frame's initial reconcile. IDLE is QUIET — it only
        // speaks when a window appears (this tab or another one) or a
        // new completion lands, so a finished/expired session can never
        // be re-announced (TASK-014: the old "expired" tail loop
        // overwrote the SUCCESS line seconds after a good pairing and
        // kept re-lying every 3 s).
        function applyStatus(data) {
            var pending = data.pending;
            if (pending && pending.seconds_left > 0) {
                if (!armed) { setState(armedLine(), true); showCountdown(true); }  // armed elsewhere (other tab/phone)
                armed = true;
                secondsLeft = pending.seconds_left;
                renderCountdown();
                stateBox.dataset.studentName = pending.student_name || '';
                rejectionNote = noteFromFeed(pending.last_rejection);
                if (rejectionNote) { setState(armedLine(), true); }
                setPollInterval(ACTIVE_MS);
                return;
            }
            // No live window: a card got paired, it expired, or nothing changed.
            var last = data.last_pairing;
            if (last && last.card_uid && last.card_uid !== lastSeenUid) {
                lastSeenUid = last.card_uid;
                setState(SUCCESS_TPL
                    .replace(':UID:', last.card_uid)
                    .replace(':NAME:', last.student_name || ''), true);
                renderRecent(data.recent_pairings);
                renderStudentCard(last);
                armed = false;
                rejectionNote = null;
                showCountdown(false);
                setPollInterval(IDLE_MS);
            } else if (armed) {
                // We were following this window and it is gone without a
                // new completion — a REAL expiry (not a post-success lie).
                setState(EXPIRED_TEXT, false);
                armed = false;
                rejectionNote = null;
                showCountdown(false);
                setPollInterval(IDLE_MS);
            }
        }

        function poll() {
            getJson('/api/v1/admin/pairing/status').then(function (r) {
                if (!r.ok) return;
                applyStatus(r.data);
            });
        }

        // TASK-020 — the realtime channel: a card paired at the reader
        // (or armed at another tab) reaches this desk within one server
        // poll beat (~300 ms) — no waiting for this page's own cadence.
        document.addEventListener('realtime:pairing', function (e) {
            applyStatus(e.detail || {});
        });

        // ONE global 1 s ticker (started once) — tick() no-ops when not armed.
        setInterval(tick, 1000);

        // Arm buttons -> the EXISTING TASK-010 endpoint, session-authed.
        // TASK-017: the clicked button spins while the window arms.
        armBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                armBtns.forEach(function (b) { b.disabled = true; });
                btn.classList.add('is-loading');
                postJson('/api/v1/admin/students/' + btn.dataset.student + '/arm-pairing')
                    .then(function (r) {
                        armBtns.forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); });
                        if (r.ok) {
                            armed = true;
                            rejectionNote = null;   // new window, no rejections yet
                            stateBox.dataset.studentName = btn.dataset.name;
                            // expires_at comes back ISO; trust the server's
                            // window. TASK-020 — clamped to the configured
                            // window: a skewed browser clock can no longer
                            // paint an absurd countdown (the poll/WS frame
                            // re-syncs to server truth within 2 s anyway).
                            var ms = Date.parse(r.data.expires_at) - Date.now();
                            secondsLeft = Math.min(WINDOW_TOTAL, Math.max(0, Math.round(ms / 1000)));
                            setState(armedLine(), true);
                            showCountdown(true);
                            setPollInterval(ACTIVE_MS);
                            poll();
                        } else {
                            setState((r.data && r.data.message) || {!! Js::from(__('app.error_generic')) !!}, false);
                        }
                    })
                    .catch(function () {
                        armBtns.forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); });
                        setState({!! Js::from(__('app.error_generic')) !!}, false);
                    });
            });
        });

        // TASK-026 — client-side roster search (real rows only).
        var rosterSearch = document.getElementById('roster-search');
        if (rosterSearch) {
            rosterSearch.addEventListener('input', function () {
                var q = rosterSearch.value.trim().toLowerCase();
                document.querySelectorAll('[data-roster] tr[data-student-row]').forEach(function (row) {
                    row.hidden = q !== '' && (row.dataset.search || '').indexOf(q) === -1;
                });
            });
        }

        // TASK-027 — per-card unpair buttons (gap D1). Confirm first (the
        // action deletes the card's tap history — the copy says so), then
        // DELETE and repaint ONLY from the confirmed server answer: the
        // chip leaves the cell, the roster truth stays server-driven
        // (textContent everywhere — a UID can never inject markup).
        unpairBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var confirmTpl = {!! Js::from(__('app.unpair_confirm', ['uid' => ':UID:', 'student' => ':NAME:'])) !!};
                window.DatumConfirm.open('unpair-confirm', {
                    title: UNPAIR_LABEL,
                    message: confirmTpl.replace(':UID:', btn.dataset.uid || '').replace(':NAME:', btn.dataset.name || ''),
                    confirmLabel: UNPAIR_LABEL,
                    onConfirm: function () { doUnpair(btn); }
                });
            });
        });

        function doUnpair(btn) {
            btn.disabled = true;
            deleteJson('/api/v1/admin/cards/' + btn.dataset.unpair)
                .then(function (r) {
                    btn.disabled = false;
                    if (r.ok) {
                        var row = document.querySelector('tr[data-student-row="' + btn.dataset.student + '"]');
                        var chip = row ? row.querySelector('[data-card-chip="' + btn.dataset.unpair + '"]') : null;
                        if (chip) { chip.remove(); }
                        var cell = row ? row.querySelector('[data-card-cell]') : null;
                        if (cell && !cell.querySelector('[data-card-chip]')) {
                            cell.textContent = NO_CARD_TEXT;
                        }
                        setState(UNPAIRED_TEXT, true);
                    } else {
                        setState((r.data && r.data.message) || {!! Js::from(__('app.error_generic')) !!}, false);
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    setState({!! Js::from(__('app.error_generic')) !!}, false);
                });
        }

        // Start following immediately: ACTIVE when a window is live (page
        // load / F5 mid-window — the rejection note comes with it), else
        // the quiet idle watch. An SSR-armed page paints its bar at once.
        renderCountdown();
        setPollInterval(armed ? ACTIVE_MS : IDLE_MS);
        poll();
    })();
</script>
@endsection
