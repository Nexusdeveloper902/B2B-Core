@extends('layouts.app')

@section('title', __('app.pairing_desk'))

@section('content')
<div class="page-head">
    <h1>{{ __('app.pairing_desk') }}</h1>
    <p class="page-meta"><span>{{ __('app.pairing_desk_intro') }}</span></p>
</div>

<section class="grid-2">
    {{-- Arming table: one click per student (replaces the curl+PAT dance) --}}
    <x-panel :label="__('app.students')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack>
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
                    <tr data-student-row="{{ $student->id }}">
                        <td data-label="{{ __('app.student') }}">{{ $student->name }}</td>
                        <td data-label="{{ __('app.class') }}">{{ $student->schoolClass?->name ?? '—' }}</td>
                        <td data-label="{{ __('app.current_card') }}">
                            @forelse($student->cards as $card)
                                <code>{{ $card->credential_uid }}</code>
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

    {{-- Live status: armed window countdown + last result, polled while armed --}}
    <x-panel :label="__('app.pairing_status')" rule>
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
             textContent — a child would not survive it). --}}
        <div id="pairing-countdown" class="countdown {{ $activeSession ? '' : 'hidden' }}"
             role="progressbar" aria-label="{{ __('app.pairing_window') }}"
             data-total="{{ $pairingWindowSeconds }}"
             @if(! $activeSession) aria-hidden="true" @endif>
            <div class="countdown-fill"></div>
        </div>
        @if(! $activeSession)
            <p class="muted" id="pairing-idle">{{ __('app.pairing_no_session') }}</p>
        @endif
    </x-panel>
</section>

<section class="grid-2">
    {{-- History: exact card->student links this platform made --}}
    <x-panel :label="__('app.pairing_recent')">
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
                        <td data-label="{{ __('app.pairing_paired_at') }}">{{ $pairing->consumed_at?->format('Y-m-d H:i') }}</td>
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
        var lastSeenUid = {!! json_encode($lastCardUid) !!};
        var armed = stateBox.dataset.initiallyArmed === '1';
        var secondsLeft = parseInt(stateBox.dataset.secondsLeft || '0', 10);
        var armBtns = Array.prototype.slice.call(document.querySelectorAll('.arm-btn'));

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
        var REJECTED_TPL = {!! json_encode(__('app.pairing_rejected', ['uid' => ':UID:', 'reason' => ':REASON:'])) !!};
        var REASON_TEXT = {
            'already_paired': {!! json_encode(__('app.pairing_reason_already_paired')) !!}
        };
        var rejectionNote = stateBox.dataset.rejectionNote || null;

        var ARMED_TPL = {!! json_encode(__('app.pairing_armed_for', ['name' => ':NAME:']) . ' — ' . __('app.pairing_seconds_left', ['s' => ':S:']) . ' ' . __('app.pairing_go_tap')) !!};

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

        function tick() {
            if (!armed) return;
            if (secondsLeft > 0) {
                secondsLeft -= 1;
            }
            renderCountdown();
            if (secondsLeft > 0) {
                setState(armedLine(), true);
            }
        }

        // One poll function, two cadences: ACTIVE while an armed window is
        // live, IDLE otherwise. IDLE is QUIET — it only speaks when a window
        // appears (this tab or another one) or a new completion lands, so a
        // finished/expired session can never be re-announced (TASK-014: the
        // old "expired" tail loop overwrote the SUCCESS line seconds after
        // a good pairing and kept re-lying every 3 s).
        function setPollInterval(ms) {
            if (pollTimer) { clearInterval(pollTimer); }
            pollTimer = setInterval(poll, ms);
        }

        function poll() {
            getJson('/api/v1/admin/pairing/status').then(function (r) {
                if (!r.ok) return;
                var pending = r.data.pending;
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
                var last = r.data.last_pairing;
                if (last && last.card_uid && last.card_uid !== lastSeenUid) {
                    lastSeenUid = last.card_uid;
                    setState({!! json_encode(__('app.pairing_success', ['uid' => ':UID:', 'name' => ':NAME:'])) !!}
                        .replace(':UID:', last.card_uid)
                        .replace(':NAME:', last.student_name || ''), true);
                    renderRecent(r.data.recent_pairings);
                    armed = false;
                    rejectionNote = null;
                    showCountdown(false);
                    setPollInterval(IDLE_MS);
                } else if (armed) {
                    // We were following this window and it is gone without a
                    // new completion — a REAL expiry (not a post-success lie).
                    setState({!! json_encode(__('app.pairing_expired')) !!}, false);
                    armed = false;
                    rejectionNote = null;
                    showCountdown(false);
                    setPollInterval(IDLE_MS);
                }
            });
        }

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
                            // expires_at comes back ISO; trust the server's window.
                            var ms = Date.parse(r.data.expires_at) - Date.now();
                            secondsLeft = Math.max(0, Math.round(ms / 1000));
                            setState(armedLine(), true);
                            showCountdown(true);
                            setPollInterval(ACTIVE_MS);
                            poll();
                        } else {
                            setState((r.data && r.data.message) || {!! json_encode(__('app.error_generic')) !!}, false);
                        }
                    })
                    .catch(function () {
                        armBtns.forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); });
                        setState({!! json_encode(__('app.error_generic')) !!}, false);
                    });
            });
        });

        // Start following immediately: ACTIVE when a window is live (page
        // load / F5 mid-window — the rejection note comes with it), else
        // the quiet idle watch. An SSR-armed page paints its bar at once.
        renderCountdown();
        setPollInterval(armed ? ACTIVE_MS : IDLE_MS);
        poll();
    })();
</script>
@endsection
