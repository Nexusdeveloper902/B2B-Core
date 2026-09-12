{{--
    TASK-027 — the student management desk (/admin/students): searchable
    roster, single-student creation, CSV bulk import. Same statefulApi
    pattern as the pairing desk (session cookie + CSRF, no PAT, no curl).
    Writes go to POST /api/v1/admin/students and POST /api/v1/admin/
    students/import; this view only renders server-side state.

    TASK-029 — the desk grows up: grade is a SELECT (typing "5°" by
    hand — degree sign included — was a chore), class creation lives
    HERE (POST /api/v1/admin/classes — the roster workflow no longer
    needs hand-written SQL anywhere), and the desk is LIVE: roster
    frames (realtime.js booted below) prepend created students and
    add created classes the moment they commit; the fetch responses
    apply the same idempotent handlers, so the page stays correct
    even with the realtime server down.
--}}
@extends('layouts.app')
@use('Illuminate\Support\Js', 'Js')

@section('title', __('app.students_page'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.students_page') }}</span>
    <h1>{{ __('app.students_page') }}</h1>
    <p class="lede-sub">{{ __('app.students_page_sub') }}</p>
</div>

<section class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Create + class + import --}}
    <x-panel :label="__('app.create_student')" rule>
        <form id="student-create-form" class="tool-form">
            <input type="text" class="bare-input" id="student-name" autocomplete="off"
                   placeholder="{{ __('app.student_name') }}" required
                   aria-label="{{ __('app.student_name') }}">
            <select id="student-grade" class="bare-select" required aria-label="{{ __('app.student_grade') }}">
                @foreach(range(0, 11) as $gradeNumber)
                    <option value="{{ $gradeNumber }}°">{{ $gradeNumber }}°</option>
                @endforeach
            </select>
            <select id="student-class" class="bare-select" required aria-label="{{ __('app.student_class') }}">
                @foreach($classes as $class)
                    <option value="{{ $class->id }}">{{ $class->name }}</option>
                @endforeach
            </select>
            <label class="check-line">
                <input type="checkbox" id="student-pae">
                {{ __('app.student_pae') }}
            </label>
            <button type="submit" class="btn btn-primary">{{ __('app.create_student') }}</button>
        </form>
        <div id="student-create-result" class="nl-answer hidden" aria-live="polite"></div>

        <hr class="rule">

        {{-- TASK-029 — class creation (the roster workflow's missing first
              step; the class select above was read-only before). --}}
        <form id="class-create-form" class="tool-form">
            <input type="text" class="bare-input" id="class-name" autocomplete="off" maxlength="255"
                   placeholder="{{ __('app.class_name') }}" required
                   aria-label="{{ __('app.class_name') }}">
            <select id="class-teacher" class="bare-select" aria-label="{{ __('app.class_teacher_optional') }}">
                <option value="">{{ __('app.class_teacher_none') }}</option>
                @foreach($teachers as $teacher)
                    <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-quiet">{{ __('app.create_class') }}</button>
        </form>
        <div id="class-create-result" class="nl-answer hidden" aria-live="polite"></div>

        <hr class="rule">

        <form id="student-import-form">
            <p class="panel-sub">{{ __('app.import_students') }} — {{ __('app.import_students_hint') }}</p>
            <div class="file-row">
                <input type="file" id="student-import-file" accept=".csv,text/csv,text/plain"
                       required aria-label="{{ __('app.choose_file') }}">
                <button type="submit" class="btn btn-quiet">{{ __('app.import_file') }}</button>
            </div>
        </form>
        <div id="student-import-result" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>

    {{-- Roster --}}
    <x-panel :label="__('app.students')" rule>
        <form method="GET" action="{{ route('admin.students') }}" class="tool-form">
            <input type="search" class="bare-input" name="q" value="{{ $search }}"
                   placeholder="{{ __('app.search_students') }}" aria-label="{{ __('app.search_students') }}">
            <button type="submit" class="btn btn-quiet">{{ __('app.search') }}</button>
            @if($search !== '')
                <a class="btn btn-quiet" href="{{ route('admin.students') }}">{{ __('app.clear') }}</a>
            @endif
        </form>

        <div class="ledger-wrap">
            <table class="ledger-table" data-stack>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.student_name') }}</th>
                    <th scope="col">{{ __('app.student_class') }}</th>
                    <th scope="col">{{ __('app.student_grade') }}</th>
                    <th scope="col">{{ __('app.pae_enrolled') }}</th>
                    <th scope="col">{{ __('app.card') }}</th>
                    <th scope="col">{{ __('app.account') }}</th>
                    <th scope="col"></th>
                </tr>
                </thead>
                <tbody id="roster-body">
                @forelse($students as $student)
                    <tr data-student-row="{{ $student->id }}" data-search="{{ mb_strtolower($student->name) }}">
                        <td data-label="{{ __('app.student_name') }}">{{ $student->name }}</td>
                        <td data-label="{{ __('app.student_class') }}">{{ $student->schoolClass?->name ?? '—' }}</td>
                        <td data-label="{{ __('app.student_grade') }}">{{ $student->grade }}</td>
                        <td data-label="{{ __('app.pae_enrolled') }}">{{ $student->pae_enrolled ? '✓' : '—' }}</td>
                        <td data-label="{{ __('app.card') }}">
                            @forelse($student->cards as $card)
                                <code>{{ \Illuminate\Support\Str::limit($card->credential_uid, 10) }}</code>
                            @empty
                                <span class="muted">{{ __('app.no_card') }}</span>
                            @endforelse
                        </td>
                        {{-- TASK-030-A — the provisioned login, or the
                             one-click backfill for pre-feature rows. --}}
                        <td data-label="{{ __('app.account') }}" data-account-cell>
                            @if($student->account)
                                <code>{{ $student->account->email }}</code>
                            @else
                                <button type="button" class="tiny-link" data-provision="{{ $student->id }}">{{ __('app.provision_account') }}</button>
                            @endif
                        </td>
                        <td data-label="">
                            <a class="tiny-link" href="{{ route('parent.timeline', $student) }}">{{ __('app.view_parent') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr id="roster-empty"><td colspan="7" class="muted">{{ __('app.no_students_found') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $students->links() }}
    </x-panel>
</section>

{{-- TASK-029 — the realtime boot node (same contract as the pairing
      desk's hidden div: realtime.js picks up [data-realtime]). --}}
<div id="students-realtime" hidden data-realtime="{{ json_encode([
    'token' => $realtimeToken,
    'expires_at' => $realtimeTokenExpires,
    'port' => (int) config('realtime.port'),
    'max_rows' => (int) config('realtime.history_limit'),
]) }}"></div>
<script src="{{ asset('js/realtime.js') }}"></script>
<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var NO_CARD = {!! Js::from(__('app.no_card')) !!};
        var VIEW_PARENT = {!! Js::from(__('app.view_parent')) !!};
        var PAE_YES = {!! Js::from(__('app.pae_enrolled_yes')) !!};
        var PAE_NO = {!! Js::from(__('app.pae_enrolled_no')) !!};
        var NO_ACCOUNT = {!! Js::from(__('app.no_account')) !!};
        var PROVISION_ACCOUNT = {!! Js::from(__('app.provision_account')) !!};
        var ACCOUNT_LABEL = {!! Js::from(__('app.account')) !!};

        function busy(btn, on) {
            btn.disabled = on;
            btn.classList.toggle('is-loading', on);
        }

        function show(el, text, ok) {
            el.classList.remove('hidden');
            el.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
            el.textContent = text;
        }

        function postJson(url, body) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().then(function (data) { return {ok: r.ok, data: data}; });
            });
        }

        // ---- TASK-029 — the LIVE roster (idempotent handlers; both the
        // fetch responses and the WS roster frames call these, whichever
        // arrives first wins and the replay is a no-op). ----

        function buildStudentRow(s) {
            var tr = document.createElement('tr');
            tr.dataset.studentRow = String(s.id);
            tr.setAttribute('data-student-row', String(s.id));
            tr.dataset.search = String(s.name || '').toLowerCase();

            var name = td(s.name);
            var klass = td(s.class_name || '—');
            var grade = td(s.grade || '—');
            var pae = td(s.pae_enrolled ? PAE_YES : PAE_NO);
            var card = document.createElement('td');
            var noCard = document.createElement('span');
            noCard.className = 'muted';
            noCard.textContent = NO_CARD;
            card.appendChild(noCard);
            // TASK-030-A — the login column: live arrivals carry the
            // provisioned email (account_email); rows without one keep
            // the one-click backfill (server-rendered rows identical).
            var account = document.createElement('td');
            account.setAttribute('data-account-cell', '');
            renderAccountCell(account, s);
            var action = document.createElement('td');
            var link = document.createElement('a');
            link.className = 'tiny-link';
            link.href = '/parent/students/' + s.id;
            link.textContent = VIEW_PARENT;
            action.appendChild(link);

            tr.appendChild(name);
            tr.appendChild(klass);
            tr.appendChild(grade);
            tr.appendChild(pae);
            tr.appendChild(card);
            tr.appendChild(account);
            tr.appendChild(action);
            return tr;
        }

        // TASK-030-A — account cell renderer (shared by live rows,
        // updates, and the provision backfill below).
        function renderAccountCell(cell, s) {
            cell.textContent = '';
            cell.setAttribute('data-label', ACCOUNT_LABEL);
            if (s.account_email) {
                var code = document.createElement('code');
                code.textContent = s.account_email;
                cell.appendChild(code);
            } else if (s.id !== undefined && s.id !== null) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tiny-link';
                btn.setAttribute('data-provision', String(s.id));
                btn.textContent = PROVISION_ACCOUNT;
                cell.appendChild(btn);
            } else {
                var none = document.createElement('span');
                none.className = 'muted';
                none.textContent = NO_ACCOUNT;
                cell.appendChild(none);
            }
        }

        function td(value) {
            var cell = document.createElement('td');
            cell.textContent = value === null || value === undefined ? '—' : String(value);
            return cell;
        }

        function updateStudentRow(row, s) {
            var cells = row.querySelectorAll('td');
            if (cells.length >= 5) {
                if (s.name !== undefined) {
                    cells[0].textContent = s.name;
                    // The client-side search reads data-search — a renamed
                    // student would otherwise be findable only by the old name.
                    row.dataset.search = String(s.name || '').toLowerCase();
                }
                if (s.class_name !== undefined) { cells[1].textContent = s.class_name || '—'; }
                if (s.grade !== undefined) { cells[2].textContent = s.grade || '—'; }
                if (s.pae_enrolled !== undefined) { cells[3].textContent = s.pae_enrolled ? PAE_YES : PAE_NO; }
            }
            // TASK-030-A — the account column updates independently of
            // the positional cells above (it owns its own selector, so
            // column order can never silently shift it).
            if (s.account_email !== undefined) {
                var accountCell = row.querySelector('[data-account-cell]');
                if (accountCell) { renderAccountCell(accountCell, s); }
            }
            return row;
        }

        function applyStudent(s) {
            if (!s || s.id === undefined || s.id === null) { return; }
            var body = document.getElementById('roster-body');
            if (!body) { return; }
            var row = body.querySelector('tr[data-student-row="' + s.id + '"]');
            if (row) {
                updateStudentRow(row, s);
            } else {
                var empty = document.getElementById('roster-empty');
                if (empty) { empty.remove(); }
                row = buildStudentRow(s);
                body.insertBefore(row, body.firstChild);
            }
            row.classList.remove('js-row-flash');
            void row.offsetWidth; // restart the flash animation
            row.classList.add('js-row-flash');
        }

        function ensureClassOption(c) {
            if (!c || c.id === undefined || c.id === null) { return; }
            var select = document.getElementById('student-class');
            if (!select) { return; }
            var existing = select.querySelector('option[value="' + c.id + '"]');
            if (existing) {
                if (c.name !== undefined) { existing.textContent = c.name; }
                return;
            }
            var opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = String(c.name);
            select.appendChild(opt);
        }

        // The roster channel (admin-only frames; the hello snapshot
        // replays through these too — see realtime.js).
        document.addEventListener('realtime:roster', function (e) {
            var update = e.detail || {};
            var payload = update.payload || {};
            if (update.type === 'student_created') { applyStudent(payload); }
            if (update.type === 'students_imported' && payload.students && payload.students.forEach) {
                payload.students.forEach(applyStudent);
            }
            if (update.type === 'class_created') { ensureClassOption(payload); }
        });

        // Create one student (the grade SELECT kills the "type the
        // degree sign" chore; the row goes live immediately).
        var createForm = document.getElementById('student-create-form');
        createForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = createForm.querySelector('button[type="submit"]');
            var box = document.getElementById('student-create-result');
            busy(btn, true);
            postJson('/api/v1/admin/students', {
                name: document.getElementById('student-name').value.trim(),
                grade: document.getElementById('student-grade').value,
                class_id: parseInt(document.getElementById('student-class').value, 10),
                pae_enrolled: document.getElementById('student-pae').checked
            }).then(function (r) {
                busy(btn, false);
                // TASK-030-A — the display-once credentials ride the
                // notice line (this response is the only place the
                // temporary password ever appears).
                var text = (r.data && r.data.message) || '{{ __('app.error_generic') }}';
                if (r.ok && r.data && r.data.account_notice) { text += '\n' + r.data.account_notice; }
                show(box, text, r.ok);
                if (r.ok) {
                    if (r.data && r.data.student) { applyStudent(r.data.student); }
                    createForm.reset();
                }
            }).catch(function () { busy(btn, false); });
        });

        // TASK-030-A — one-click login backfill for pre-feature rows
        // (delegated: live-prepended rows carry their own buttons too).
        document.getElementById('roster-body').addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-provision]') : null;
            if (!btn) { return; }
            var box = document.getElementById('student-create-result');
            busy(btn, true);
            postJson('/api/v1/admin/students/' + btn.getAttribute('data-provision') + '/account', {})
                .then(function (r) {
                    busy(btn, false);
                    var text = (r.data && r.data.message) || '{{ __('app.error_generic') }}';
                    show(box, text, r.ok);
                    if (r.ok && r.data && r.data.account) {
                        var row = btn.closest('tr[data-student-row]');
                        if (row) {
                            var cell = row.querySelector('[data-account-cell]');
                            if (cell) { renderAccountCell(cell, {account_email: r.data.account.email}); }
                        }
                    }
                }).catch(function () { busy(btn, false); });
        });

        // TASK-029 — create a class (name + optional homeroom teacher).
        var classForm = document.getElementById('class-create-form');
        classForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = classForm.querySelector('button[type="submit"]');
            var box = document.getElementById('class-create-result');
            var teacher = document.getElementById('class-teacher').value;
            busy(btn, true);
            postJson('/api/v1/admin/classes', {
                name: document.getElementById('class-name').value.trim(),
                teacher_user_id: teacher === '' ? null : parseInt(teacher, 10)
            }).then(function (r) {
                busy(btn, false);
                show(box, (r.data && r.data.message) || '{{ __('app.error_generic') }}', r.ok);
                if (r.ok) {
                    if (r.data && r.data.class) { ensureClassOption(r.data.class); }
                    classForm.reset();
                }
            }).catch(function () { busy(btn, false); });
        });

        // CSV bulk import (multipart — no Content-Type header, the browser
        // sets the boundary).
        var importForm = document.getElementById('student-import-form');
        importForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fileInput = document.getElementById('student-import-file');
            if (!fileInput.files.length) { return; }
            var btn = importForm.querySelector('button[type="submit"]');
            var box = document.getElementById('student-import-result');
            busy(btn, true);

            var body = new FormData();
            body.append('file', fileInput.files[0]);

            fetch('/api/v1/admin/students/import', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: body
            }).then(function (r) {
                return r.json().then(function (data) { return {ok: r.ok, data: data}; });
            }).then(function (r) {
                busy(btn, false);
                if (r.data && r.data.errors && r.data.errors.length) {
                    var lines = [r.data.message || ''];
                    r.data.errors.forEach(function (err) {
                        lines.push('· ' + (err.row > 0 ? ('[' + err.row + '] ') : '') + err.message);
                    });
                    show(box, lines.join('\n'), r.ok);
                } else {
                    show(box, (r.data && r.data.message) || '{{ __('app.error_generic') }}', r.ok);
                }
                if (r.ok && r.data && r.data.students && r.data.students.forEach) {
                    // Live roster, fetch-first (the WS frame replays no-op).
                    r.data.students.forEach(function (entry) {
                        applyStudent({
                            id: entry.id,
                            name: entry.name,
                            class_name: entry.class_name,
                            // TASK-030-A — imported rows arrive with
                            // their provisioned login.
                            account_email: entry.account_email
                        });
                    });
                }
            }).catch(function () { busy(btn, false); });
        });
    })();
</script>
@endsection
