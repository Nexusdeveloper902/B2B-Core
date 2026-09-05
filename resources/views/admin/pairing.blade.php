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
            <table class="ledger-table">
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
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->schoolClass?->name ?? '—' }}</td>
                        <td>
                            @forelse($student->cards as $card)
                                <code>{{ $card->credential_uid }}</code>
                            @empty
                                <span class="muted">{{ __('app.no_card') }}</span>
                            @endforelse
                        </td>
                        <td>
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
        @if(! $activeSession)
            <p class="muted" id="pairing-idle">{{ __('app.pairing_no_session') }}</p>
        @endif
    </x-panel>
</section>

<section class="grid-2">
    {{-- History: exact card->student links this platform made --}}
    <x-panel :label="__('app.pairing_recent')">
        <div class="ledger-wrap" id="recent-wrap">
            <table class="ledger-table">
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
                        <td><code>{{ $pairing->card?->credential_uid }}</code></td>
                        <td>{{ $pairing->student?->name }}</td>
                        <td>{{ $pairing->consumed_at?->format('Y-m-d H:i') }}</td>
                        <td>{{ $pairing->reader?->label ?? '—' }}</td>
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
                    if (!armed) { setState(armedLine(), true); }  // armed elsewhere (other tab/phone)
                    armed = true;
                    secondsLeft = pending.seconds_left;
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
                    setPollInterval(IDLE_MS);
                } else if (armed) {
                    // We were following this window and it is gone without a
                    // new completion — a REAL expiry (not a post-success lie).
                    setState({!! json_encode(__('app.pairing_expired')) !!}, false);
                    armed = false;
                    rejectionNote = null;
                    setPollInterval(IDLE_MS);
                }
            });
        }

        // ONE global 1 s ticker (started once) — tick() no-ops when not armed.
        setInterval(tick, 1000);

        // Arm buttons -> the EXISTING TASK-010 endpoint, session-authed.
        armBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                armBtns.forEach(function (b) { b.disabled = true; });
                postJson('/api/v1/admin/students/' + btn.dataset.student + '/arm-pairing')
                    .then(function (r) {
                        armBtns.forEach(function (b) { b.disabled = false; });
                        if (r.ok) {
                            armed = true;
                            rejectionNote = null;   // new window, no rejections yet
                            stateBox.dataset.studentName = btn.dataset.name;
                            // expires_at comes back ISO; trust the server's window.
                            var ms = Date.parse(r.data.expires_at) - Date.now();
                            secondsLeft = Math.max(0, Math.round(ms / 1000));
                            setState(armedLine(), true);
                            setPollInterval(ACTIVE_MS);
                            poll();
                        } else {
                            setState((r.data && r.data.message) || {!! json_encode(__('app.error_generic')) !!}, false);
                        }
                    })
                    .catch(function () {
                        armBtns.forEach(function (b) { b.disabled = false; });
                        setState({!! json_encode(__('app.error_generic')) !!}, false);
                    });
            });
        });

        // Start following immediately: ACTIVE when a window is live (page
        // load / F5 mid-window — the rejection note comes with it), else
        // the quiet idle watch.
        setPollInterval(armed ? ACTIVE_MS : IDLE_MS);
        poll();
    })();
</script>
@endsection
