{{--
    TASK-037 — the admin settings desk (ADR-055): every safe
    presence/PAE environment knob in one bilingual form — meal serving
    windows, the attendance late cutoff, the card-pairing window and
    the student account conventions. Values are the EFFECTIVE settings
    (stored override → config default); writes go through the admin API
    (PUT /api/v1/admin/settings) with the same statefulApi fetch pattern
    the other desks use, so CSRF + session auth apply.

    Secrets never appear here — only behavioral configuration.
--}}
@extends('layouts.app')

@section('title', __('app.settings_page'))

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ __('app.settings_page') }}</h1>
    <p class="lede muted">{{ __('app.settings_sub') }}</p>
</div>

<div class="settings-grid">
    <form id="settings-form" class="panel settings-panel" novalidate>
        <h2 class="panel-label">{{ __('app.settings_pae_group') }}</h2>
        <p class="muted small sp-b-sm">{{ __('app.settings_pae_group_hint') }}</p>

        <div class="settings-row">
            <label class="field" for="pae.breakfast_start">
                <span>{{ __('app.settings_breakfast_start') }}</span>
                <input type="time" id="pae.breakfast_start" name="pae.breakfast_start"
                       value="{{ $settings['pae.breakfast_start'] }}"
                       @class(['is-custom' => in_array('pae.breakfast_start', $customized)])>
            </label>
            <label class="field" for="pae.breakfast_end">
                <span>{{ __('app.settings_breakfast_end') }}</span>
                <input type="time" id="pae.breakfast_end" name="pae.breakfast_end"
                       value="{{ $settings['pae.breakfast_end'] }}"
                       @class(['is-custom' => in_array('pae.breakfast_end', $customized)])>
            </label>
        </div>

        <div class="settings-row">
            <label class="field" for="pae.lunch_start">
                <span>{{ __('app.settings_lunch_start') }}</span>
                <input type="time" id="pae.lunch_start" name="pae.lunch_start"
                       value="{{ $settings['pae.lunch_start'] }}"
                       @class(['is-custom' => in_array('pae.lunch_start', $customized)])>
            </label>
            <label class="field" for="pae.lunch_end">
                <span>{{ __('app.settings_lunch_end') }}</span>
                <input type="time" id="pae.lunch_end" name="pae.lunch_end"
                       value="{{ $settings['pae.lunch_end'] }}"
                       @class(['is-custom' => in_array('pae.lunch_end', $customized)])>
            </label>
        </div>

        <h2 class="panel-label sp-t-md">{{ __('app.settings_presence_group') }}</h2>

        <label class="field" for="attendance.late_cutoff">
            <span>{{ __('app.settings_late_cutoff') }}</span>
            <input type="time" id="attendance.late_cutoff" name="attendance.late_cutoff"
                   value="{{ $settings['attendance.late_cutoff'] }}"
                   @class(['is-custom' => in_array('attendance.late_cutoff', $customized)])>
            <span class="muted small">{{ __('app.settings_late_cutoff_hint') }}</span>
        </label>

        <label class="field" for="pairing.window_seconds">
            <span>{{ __('app.settings_pairing_window') }}</span>
            <input type="number" id="pairing.window_seconds" name="pairing.window_seconds" min="10" max="600"
                   value="{{ $settings['pairing.window_seconds'] }}"
                   @class(['is-custom' => in_array('pairing.window_seconds', $customized)])>
            <span class="muted small">{{ __('app.settings_pairing_window_hint') }}</span>
        </label>

        <h2 class="panel-label sp-t-md">{{ __('app.settings_accounts_group') }}</h2>

        <label class="field" for="accounts.student_email_domain">
            <span>{{ __('app.settings_email_domain') }}</span>
            <input type="text" id="accounts.student_email_domain" name="accounts.student_email_domain"
                   value="{{ $settings['accounts.student_email_domain'] }}" autocomplete="off"
                   @class(['is-custom' => in_array('accounts.student_email_domain', $customized)])>
        </label>

        <label class="field" for="accounts.student_initial_password">
            <span>{{ __('app.settings_initial_password') }}</span>
            <input type="text" id="accounts.student_initial_password" name="accounts.student_initial_password"
                   value="{{ $settings['accounts.student_initial_password'] }}" autocomplete="off"
                   @class(['is-custom' => in_array('accounts.student_initial_password', $customized)])>
            <span class="muted small">{{ __('app.settings_initial_password_hint') }}</span>
        </label>

        <div class="tool-actions">
            <button type="submit" class="btn primary" id="settings-save">
                <span class="btn-label">{{ __('app.settings_save') }}</span>
            </button>
            <span class="muted small">{{ __('app.settings_custom_note') }}</span>
        </div>

        <div id="settings-result" class="nl-answer answer-ok hidden" role="status"></div>
    </form>
</div>

<script>
(function () {
    'use strict';

    var form = document.getElementById('settings-form');
    if (!form) { return; }

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var result = document.getElementById('settings-result');
    var saveBtn = document.getElementById('settings-save');
    var savedToast = @json(__('app.toast_settings_saved'));
    var savedFailToast = @json(__('app.toast_settings_failed'));
    var networkToast = @json(__('app.toast_network_error'));

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var settings = {};
        form.querySelectorAll('input[name]').forEach(function (input) {
            settings[input.name] = input.value;
        });

        saveBtn.disabled = true;
        saveBtn.classList.add('is-loading');

        fetch('/api/v1/admin/settings', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf
            },
            body: JSON.stringify({ settings: settings })
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        }).then(function (r) {
            saveBtn.disabled = false;
            saveBtn.classList.remove('is-loading');

            result.classList.remove('hidden', 'answer-ok', 'answer-error');
            result.className = 'nl-answer ' + (r.ok ? 'answer-ok' : 'answer-error');

            if (r.ok) {
                result.textContent = (r.data && r.data.message) || '';
                if (window.PulseToast) { PulseToast.success(savedToast); }
            } else {
                var errors = (r.data && r.data.errors) || {};
                var lines = [(r.data && r.data.message) || ''];
                Object.keys(errors).forEach(function (key) {
                    lines.push(key + ': ' + errors[key].join(' '));
                });
                result.textContent = lines.filter(Boolean).join('\n');
                if (window.PulseToast) { PulseToast.error(savedFailToast, ''); }
            }
        }).catch(function () {
            saveBtn.disabled = false;
            saveBtn.classList.remove('is-loading');
            if (window.PulseToast) { PulseToast.error(networkToast); }
        });
    });
})();
</script>
@endsection
