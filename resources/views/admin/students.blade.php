{{--
    TASK-027 — the student management desk (/admin/students): searchable
    roster, single-student creation, CSV bulk import. Same statefulApi
    pattern as the pairing desk (session cookie + CSRF, no PAT, no curl).
    Writes go to POST /api/v1/admin/students and POST /api/v1/admin/
    students/import; this view only renders server-side state.
--}}
@extends('layouts.app')

@section('title', __('app.students_page'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.students_page') }}</span>
    <h1>{{ __('app.students_page') }}</h1>
    <p class="lede-sub">{{ __('app.students_page_sub') }}</p>
</div>

<section class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Create + import --}}
    <x-panel :label="__('app.create_student')" rule>
        <form id="student-create-form" class="tool-form">
            <input type="text" class="bare-input" id="student-name" autocomplete="off"
                   placeholder="{{ __('app.student_name') }}" required
                   aria-label="{{ __('app.student_name') }}">
            <input type="text" class="bare-input" id="student-grade" autocomplete="off"
                   placeholder="{{ __('app.student_grade') }}" required
                   aria-label="{{ __('app.student_grade') }}">
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

        <form id="student-import-form">
            <p class="panel-sub">{{ __('app.import_students') }} — {{ __('app.import_students_hint') }}</p>
            <div class="tool-form">
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
                    <th scope="col"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($students as $student)
                    <tr>
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
                        <td data-label="">
                            <a class="tiny-link" href="{{ route('parent.timeline', $student) }}">{{ __('app.view_parent') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('app.no_students_found') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $students->links() }}
    </x-panel>
</section>

<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;

        function busy(btn, on) {
            btn.disabled = on;
            btn.classList.toggle('is-loading', on);
        }

        function show(el, text, ok) {
            el.classList.remove('hidden');
            el.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
            el.textContent = text;
        }

        // Create one student.
        var createForm = document.getElementById('student-create-form');
        createForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = createForm.querySelector('button[type="submit"]');
            var box = document.getElementById('student-create-result');
            busy(btn, true);
            fetch('/api/v1/admin/students', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: JSON.stringify({
                    name: document.getElementById('student-name').value.trim(),
                    grade: document.getElementById('student-grade').value.trim(),
                    class_id: parseInt(document.getElementById('student-class').value, 10),
                    pae_enrolled: document.getElementById('student-pae').checked
                })
            }).then(function (r) {
                return r.json().then(function (data) { return {ok: r.ok, data: data}; });
            }).then(function (r) {
                busy(btn, false);
                show(box, (r.data && r.data.message) || '{{ __('app.error_generic') }}', r.ok);
                if (r.ok) { createForm.reset(); }
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
            }).catch(function () { busy(btn, false); });
        });
    })();
</script>
@endsection
