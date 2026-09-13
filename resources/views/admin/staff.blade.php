{{--
    TASK-038 — the staff accounts desk (/admin/staff): create
    admin/teacher/kitchen logins (teachers optionally take homeroom
    classes in the same request) and list who can log in as staff.
    Same statefulApi pattern as the students desk (session cookie +
    CSRF, no PAT, no curl). Writes go to POST /api/v1/admin/staff;
    this view only renders server-side state.

    No realtime channel here: staff rows have no roster-style live
    feed (unlike students/classes/readers), so the fetch response
    prepends the row itself. Student logins are deliberately NOT on
    this page — they are the 1:1 account layer minted by the
    students desk (ADR-033/ADR-044).
--}}
@extends('layouts.app')
@use('Illuminate\Support\Js', 'Js')

@section('title', __('app.staff_page'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.staff_page') }}</span>
    <h1>{{ __('app.staff_page') }}</h1>
    <p class="lede-sub">{{ __('app.staff_page_sub') }}</p>
</div>

<section class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Create (roomy stacked form — the dense tool-form row read as cramped). --}}
    <x-panel :label="__('app.create_staff_account')" rule>
        <form id="staff-create-form" class="settings-panel" autocomplete="off">
            <label class="field">
                <span>{{ __('app.staff_name') }}</span>
                <input type="text" id="staff-name" autocomplete="off" required
                       aria-label="{{ __('app.staff_name') }}">
            </label>
            <label class="field">
                <span>{{ __('app.staff_email') }}</span>
                <input type="email" id="staff-email" autocomplete="off" required
                       data-email-domain="{{ $emailDomain }}"
                       aria-label="{{ __('app.staff_email') }}">
                <span class="muted small">{{ __('app.staff_email_hint') }}</span>
            </label>
            <label class="field">
                <span>{{ __('app.staff_role') }}</span>
                <select id="staff-role" required aria-label="{{ __('app.staff_role') }}">
                    @foreach($roles as $role)
                        <option value="{{ $role }}">{{ __('app.role_'.$role) }}</option>
                    @endforeach
                </select>
            </label>
            <div class="settings-row">
                <label class="field">
                    <span>{{ __('app.staff_temp_password') }}</span>
                    <input type="password" id="staff-password" autocomplete="new-password"
                           required minlength="8" aria-label="{{ __('app.staff_temp_password') }}">
                </label>
                <label class="field">
                    <span>{{ __('app.staff_temp_password_confirm') }}</span>
                    <input type="password" id="staff-password-confirmation" autocomplete="new-password"
                           required minlength="8" aria-label="{{ __('app.staff_temp_password_confirm') }}">
                </label>
            </div>
            <div class="staff-classes">
                <span class="staff-classes-title">{{ __('app.staff_classes_optional') }}</span>
                <p class="muted small">{{ __('app.staff_classes_hint') }}</p>
                @forelse($classes as $class)
                    <label class="check-line">
                        <input type="checkbox" name="staff-classes" value="{{ $class->id }}">
                        {{ $class->name }}
                    </label>
                @empty
                    <p class="muted small">{{ __('app.staff_all_assigned') }}</p>
                @endforelse
            </div>
            <div>
                <button type="submit" class="btn btn-primary">{{ __('app.create_staff_account') }}</button>
            </div>
        </form>
        <div id="staff-create-result" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>

    {{-- List --}}
    <x-panel :label="__('app.staff_list')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.staff_name') }}</th>
                    <th scope="col">{{ __('app.staff_email') }}</th>
                    <th scope="col">{{ __('app.staff_role') }}</th>
                    <th scope="col">{{ __('app.staff_classes_optional') }}</th>
                </tr>
                </thead>
                <tbody id="staff-body">
                @forelse($staff as $member)
                    <tr data-staff-row="{{ $member->id }}">
                        <td data-label="{{ __('app.staff_name') }}">{{ $member->name }}</td>
                        <td data-label="{{ __('app.staff_email') }}"><code>{{ $member->email }}</code></td>
                        <td data-label="{{ __('app.staff_role') }}">{{ __('app.role_'.$member->role) }}</td>
                        <td data-label="{{ __('app.staff_classes_optional') }}">
                            {{ $member->classes->pluck('name')->join(', ') ?: __('app.staff_no_classes') }}
                        </td>
                    </tr>
                @empty
                    <tr id="staff-empty"><td colspan="4" class="muted">{{ __('app.report_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</section>

<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var ERROR_GENERIC = {!! Js::from(__('app.error_generic')) !!};
        var TOAST_CREATED = {!! Js::from(__('app.toast_staff_created')) !!};
        var TOAST_FAILED = {!! Js::from(__('app.toast_staff_create_failed')) !!};
        var TOAST_NETWORK = {!! Js::from(__('app.toast_network_error')) !!};
        var NO_CLASSES = {!! Js::from(__('app.staff_no_classes')) !!};
        var ROLE_LABELS = {!! Js::from([
            'admin' => __('app.role_admin'),
            'teacher' => __('app.role_teacher'),
            'kitchen' => __('app.role_kitchen'),
        ]) !!};

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

        function td(value) {
            var cell = document.createElement('td');
            cell.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
            return cell;
        }

        function applyStaff(s) {
            if (!s || s.id === undefined || s.id === null) { return; }
            var body = document.getElementById('staff-body');
            if (!body) { return; }
            var empty = document.getElementById('staff-empty');
            if (empty) { empty.remove(); }
            var tr = document.createElement('tr');
            tr.setAttribute('data-staff-row', String(s.id));
            tr.appendChild(td(s.name));
            var email = document.createElement('td');
            var code = document.createElement('code');
            code.textContent = s.email || '';
            email.appendChild(code);
            tr.appendChild(email);
            tr.appendChild(td(ROLE_LABELS[s.role] || s.role));
            tr.appendChild(td((s.classes && s.classes.length) ? s.classes.join(', ') : NO_CLASSES));
            body.insertBefore(tr, body.firstChild);
            tr.classList.remove('js-row-flash');
            void tr.offsetWidth; // restart the flash animation
            tr.classList.add('js-row-flash');
        }

        var form = document.getElementById('staff-create-form');
        // Email auto-suggest: {ascii-name}.{role}@{settings-domain},
        // refreshed while the admin hasn't typed a custom address
        // (first manual edit wins and sticks).
        var nameInput = document.getElementById('staff-name');
        var roleInput = document.getElementById('staff-role');
        var emailInput = document.getElementById('staff-email');
        var emailDirty = false;
        function slugifyName(value) {
            var ascii = String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            var slug = ascii.toLowerCase().replace(/[^a-z0-9]+/g, '.').replace(/^\.+|\.+$/g, '');
            return slug === '' ? 'staff' : slug;
        }
        function suggestEmail() {
            if (emailDirty || !nameInput || !roleInput || !emailInput) { return; }
            var domain = emailInput.dataset.emailDomain || '';
            emailInput.value = slugifyName(nameInput.value) + '.' + roleInput.value + '@' + domain;
        }
        if (emailInput) {
            emailInput.addEventListener('input', function () { emailDirty = true; });
        }
        if (nameInput) { nameInput.addEventListener('input', suggestEmail); }
        if (roleInput) { roleInput.addEventListener('change', suggestEmail); }
        suggestEmail();
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            var box = document.getElementById('staff-create-result');
            busy(btn, true);
            var classIds = Array.prototype.map.call(
                form.querySelectorAll('input[name="staff-classes"]:checked'),
                function (box) { return parseInt(box.value, 10); }
            );
            postJson('/api/v1/admin/staff', {
                name: document.getElementById('staff-name').value.trim(),
                email: document.getElementById('staff-email').value.trim(),
                role: document.getElementById('staff-role').value,
                password: document.getElementById('staff-password').value,
                password_confirmation: document.getElementById('staff-password-confirmation').value,
                class_ids: classIds
            }).then(function (r) {
                busy(btn, false);
                // The display-once credentials ride the notice line
                // (this response is the only place the temporary
                // password ever appears).
                var text = (r.data && r.data.message) || ERROR_GENERIC;
                if (r.ok && r.data && r.data.account_notice) { text += '\n' + r.data.account_notice; }
                show(box, text, r.ok);
                if (r.ok) {
                    if (r.data && r.data.staff) { applyStaff(r.data.staff); }
                    form.reset();
                    emailDirty = false;
                    suggestEmail();
                    if (window.PulseToast) { PulseToast.success(TOAST_CREATED); }
                } else if (window.PulseToast) {
                    PulseToast.error(TOAST_FAILED, (r.data && r.data.message) || '');
                }
            }).catch(function () {
                busy(btn, false);
                if (window.PulseToast) { PulseToast.error(TOAST_NETWORK); }
            });
        });
    })();
</script>
@endsection
