{{--
    TASK-027 — the reader management desk (/admin/readers): rename a
    reader and switch its active mode in one update. Since TASK-035
    this is the ONLY readers surface (the admin dashboard's quick
    mode-form is gone). Writes go to
    PUT /api/v1/admin/readers/{id} (label + active_event_type).

    TASK-030-B (ADR-045) — the desk grows up: readers are CREATED here
    (POST /api/v1/admin/readers — label + type + initial mode, the API
    key is server-generated and shown EXACTLY ONCE), and a compromised
    or lost key is ROTATED here (POST .../{id}/rotate-key, behind a
    confirm — rotation bricks the fielded reader until re-flashed).
    The table always renders (empty-row, never no-table) so live
    arrivals prepend from zero; save/rotate handlers are delegated.
--}}
@extends('layouts.app')
@use('Illuminate\Support\Js', 'Js')

@section('title', __('app.readers_page'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.readers_page') }}</span>
    <h1>{{ __('app.readers_page') }}</h1>
    <p class="lede-sub">{{ __('app.readers_page_sub') }}</p>
</div>

{{-- TASK-034 — even split (not wide-left): the 4-column editable
      roster needs the width more than the create form does; the
      action buttons were scrolling out of the narrow panel. --}}
<section class="grid-2" data-reveal>
    {{-- Create a reader (the key comes back exactly once). --}}
    <x-panel :label="__('app.create_reader')" rule>
        <form id="reader-create-form" class="tool-form" autocomplete="off">
            <input type="text" class="bare-input" id="reader-name-new" autocomplete="off" maxlength="255"
                   placeholder="{{ __('app.reader_name') }}" required minlength="3"
                   aria-label="{{ __('app.reader_name') }}">
            <select id="reader-type-new" class="bare-select" required aria-label="{{ __('app.reader_type_label') }}">
                @foreach(\App\Enums\ReaderType::cases() as $readerType)
                    <option value="{{ $readerType->value }}">{{ __('app.reader_type_'.$readerType->value) }}</option>
                @endforeach
            </select>
            <select id="reader-mode-new" class="bare-select" required aria-label="{{ __('app.reader_mode_label') }}">
                @foreach(\App\Enums\EventType::cases() as $eventType)
                    <option value="{{ $eventType->value }}">{{ __('app.event_type_'.$eventType->value) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary">{{ __('app.create_reader') }}</button>
        </form>
        <div id="reader-create-result" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>

    <x-panel :label="__('app.readers')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.reader_name') }}</th>
                    <th scope="col">{{ __('app.reader_type') }}</th>
                    <th scope="col">{{ __('app.active_mode') }}</th>
                    <th scope="col"></th>
                </tr>
                </thead>
                <tbody id="readers-body">
                @forelse($readers as $reader)
                    <tr data-reader-row="{{ $reader->id }}">
                        <td data-label="{{ __('app.reader_name') }}">
                            <input type="text" class="bare-input reader-label"
                                   value="{{ $reader->label }}" data-original="{{ $reader->label }}"
                                   aria-label="{{ __('app.reader_name') }}" autocomplete="off"
                                   id="label-{{ $reader->id }}" maxlength="255">
                        </td>
                        <td data-label="{{ __('app.reader_type') }}"><code>{{ __('app.reader_type_'.$reader->type->value) }}</code></td>
                        <td data-label="{{ __('app.active_mode') }}">
                            <select class="bare-select reader-mode" id="mode-{{ $reader->id }}"
                                    aria-label="{{ __('app.active_mode') }}">
                                @foreach(\App\Enums\EventType::cases() as $eventType)
                                    <option value="{{ $eventType->value }}"
                                            @selected($reader->active_event_type === $eventType->value)>
                                        {{ __('app.event_type_'.$eventType->value) }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td data-label="" class="row-actions">
                            <button type="button" class="btn btn-primary btn-small reader-save"
                                    data-reader="{{ $reader->id }}">
                                {{ __('app.save_reader') }}
                            </button>
                            <button type="button" class="reader-dots" popovertarget="reader-menu-{{ $reader->id }}"
                                    aria-label="{{ __('app.reader_more_actions') }}"><span class="material-symbols-outlined is-20" aria-hidden="true">more_vert</span></button>
                            <div id="reader-menu-{{ $reader->id }}" popover class="reader-menu">
                                <button type="button" class="btn btn-quiet btn-small reader-rotate"
                                        data-reader="{{ $reader->id }}" data-label="{{ $reader->label }}">
                                    {{ __('app.rotate_key') }}
                                </button>
                                <button type="button" class="btn btn-quiet btn-small btn-danger reader-delete"
                                        data-reader="{{ $reader->id }}" data-label="{{ $reader->label }}">
                                    {{ __('app.delete_reader') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr id="readers-empty"><td colspan="4" class="muted">{{ __('app.no_readers') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div id="reader-result" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>
</section>

<x-confirm-modal id="reader-confirm" />

{{-- TASK-029 — the desk is LIVE: reader changes (from either surface —
      this page or the admin dashboard's mode form) repaint the row the
      moment they commit (roster frames, realtime.js). --}}
<div id="readers-realtime" hidden data-realtime="{{ json_encode([
    'token' => $realtimeToken,
    'expires_at' => $realtimeTokenExpires,
    'port' => (int) config('realtime.port'),
    'max_rows' => (int) config('realtime.history_limit'),
]) }}"></div>
<script src="{{ asset('js/realtime.js') }}"></script>
<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var READER_TYPES = {!! Js::from([
            'classroom' => __('app.reader_type_classroom'),
            'pae' => __('app.reader_type_pae'),
            'recycling' => __('app.reader_type_recycling'),
            'entry' => __('app.reader_type_entry'),
        ]) !!};
        var MORE_LABEL = {!! Js::from(__('app.reader_more_actions')) !!};
        var SAVE_LABEL = {!! Js::from(__('app.save_reader')) !!};
        var ROTATE_LABEL = {!! Js::from(__('app.rotate_key')) !!};
        var DELETE_LABEL = {!! Js::from(__('app.delete_reader')) !!};
        var DELETE_CONFIRM = {!! Js::from(__('app.delete_reader_confirm', ['label' => ':LABEL:'])) !!};
        var NO_READERS_MSG = {!! Js::from(__('app.no_readers')) !!};
        var READER_NAME_LABEL = {!! Js::from(__('app.reader_name')) !!};
        var ACTIVE_MODE_LABEL = {!! Js::from(__('app.active_mode')) !!};
        var ROTATE_CONFIRM = {!! Js::from(__('app.rotate_key_confirm', ['label' => ':LABEL:'])) !!};
        // Finding 8 (the TASK-014 Blade lesson, applied): server strings
        // NEVER ride escaped-echo tags into JS literals — translators'
        // quotes would break the script. JSON-encoded vars only.
        var READER_UPDATED_MSG = {!! Js::from(__('app.reader_updated')) !!};
        var ERROR_GENERIC_MSG = {!! Js::from(__('app.error_generic')) !!};
        // Toast acknowledgments (details stay in the result boxes).
        var TOAST_READER_CREATED = {!! Js::from(__('app.toast_reader_created')) !!};
        var TOAST_READER_SAVED = {!! Js::from(__('app.toast_reader_saved')) !!};
        var TOAST_READER_SAVE_FAILED = {!! Js::from(__('app.toast_reader_save_failed')) !!};
        var TOAST_KEY_ROTATED = {!! Js::from(__('app.toast_key_rotated')) !!};
        var TOAST_READER_DELETED = {!! Js::from(__('app.toast_reader_deleted')) !!};
        var READER_DELETED_MSG = {!! Js::from(__('app.reader_deleted')) !!};
        var TOAST_NETWORK = {!! Js::from(__('app.toast_network_error')) !!};

        function busy(btn, on) {
            btn.disabled = on;
            btn.classList.toggle('is-loading', on);
        }

        function show(el, text, ok) {
            el.classList.remove('hidden');
            el.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
            el.textContent = text;
        }

        function postJson(url, body, method) {
            return fetch(url, {
                method: method || 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: JSON.stringify(body || {})
            }).then(function (r) {
                return r.json().then(function (data) { return {ok: r.ok, data: data}; });
            });
        }

        // TASK-030-B — live reader rows. reader_updated repaints the
        // known row (never clobbering the input being typed in);
        // reader_created prepends the full editable row (the mode
        // options are cloned from the create form — one option source).
        // Finding 11: the option template is load-bearing — without
        // it every live arrival would throw and kill the roster
        // handler. Degrade to a bare select, never a fatal.
        function buildModeSelect(id, active) {
            var select = document.createElement('select');
            select.className = 'bare-select reader-mode';
            select.id = 'mode-' + id;
            select.setAttribute('aria-label', ACTIVE_MODE_LABEL);
            var template = document.getElementById('reader-mode-new');
            if (!template || !template.options || !template.options.length) { return select; }
            Array.prototype.forEach.call(template.options, function (opt) {
                var clone = document.createElement('option');
                clone.value = opt.value;
                clone.textContent = opt.text;
                if (opt.value === active) { clone.selected = true; }
                select.appendChild(clone);
            });
            return select;
        }

        function buildReaderRow(r) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-reader-row', String(r.id));

            var nameCell = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'bare-input reader-label';
            input.autocomplete = 'off';
            input.value = r.label || '';
            input.dataset.original = r.label || '';
            input.id = 'label-' + r.id;
            input.maxLength = 255;
            input.setAttribute('aria-label', READER_NAME_LABEL);
            nameCell.appendChild(input);

            var typeCell = document.createElement('td');
            var code = document.createElement('code');
            code.textContent = READER_TYPES[r.type] || r.type || '—';
            typeCell.appendChild(code);

            var modeCell = document.createElement('td');
            modeCell.appendChild(buildModeSelect(r.id, r.active_event_type));

            var actionCell = document.createElement('td');
            actionCell.className = 'row-actions';
            var save = document.createElement('button');
            save.type = 'button';
            save.className = 'btn btn-primary btn-small reader-save';
            save.dataset.reader = String(r.id);
            save.textContent = SAVE_LABEL;
            var rotate = document.createElement('button');
            rotate.type = 'button';
            rotate.className = 'btn btn-quiet btn-small reader-rotate';
            rotate.dataset.reader = String(r.id);
            rotate.dataset.label = r.label || '';
            rotate.textContent = ROTATE_LABEL;
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-quiet btn-small btn-danger reader-delete';
            del.dataset.reader = String(r.id);
            del.dataset.label = r.label || '';
            del.textContent = DELETE_LABEL;
            var dots = document.createElement('button');
            dots.type = 'button';
            dots.className = 'reader-dots';
            dots.setAttribute('popovertarget', 'reader-menu-' + r.id);
            dots.setAttribute('aria-label', MORE_LABEL);
            var icon = document.createElement('span');
            icon.className = 'material-symbols-outlined is-20';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = 'more_vert';
            dots.appendChild(icon);
            var more = document.createElement('div');
            more.id = 'reader-menu-' + r.id;
            more.setAttribute('popover', '');
            more.className = 'reader-menu';
            more.appendChild(rotate);
            more.appendChild(del);
            actionCell.appendChild(save);
            actionCell.appendChild(dots);
            actionCell.appendChild(more);

            tr.appendChild(nameCell);
            tr.appendChild(typeCell);
            tr.appendChild(modeCell);
            tr.appendChild(actionCell);
            return tr;
        }

        function repaintReaderRow(r) {
            var label = document.getElementById('label-' + r.id);
            if (label && document.activeElement !== label && r.label !== undefined) {
                label.value = r.label;
                label.dataset.original = r.label;
            }
            var mode = document.getElementById('mode-' + r.id);
            if (mode && document.activeElement !== mode && r.active_event_type !== undefined) {
                mode.value = r.active_event_type;
            }
            var rotate = document.querySelector('.reader-rotate[data-reader="' + r.id + '"]');
            if (rotate && r.label !== undefined) { rotate.dataset.label = r.label; }
            var del = document.querySelector('.reader-delete[data-reader="' + r.id + '"]');
            if (del && r.label !== undefined) { del.dataset.label = r.label; }
        }

        function removeReaderRow(id) {
            var sid = String(id);
            if (!/^\d+$/.test(sid)) { return; }
            var body = document.getElementById('readers-body');
            if (!body) { return; }
            var row = body.querySelector('tr[data-reader-row="' + sid + '"]');
            if (row) { row.remove(); }
            if (!body.querySelector('tr[data-reader-row]')) {
                var empty = document.createElement('tr');
                empty.id = 'readers-empty';
                var cell = document.createElement('td');
                cell.colSpan = 4;
                cell.className = 'muted';
                cell.textContent = NO_READERS_MSG;
                empty.appendChild(cell);
                body.appendChild(empty);
            }
        }

        // Finding 12: ids ride string concatenation into selectors —
        // only numeric server ids ever build rows (the WS channel is
        // admin-only, but hygiene is free).
        function applyReader(r) {
            if (!r || r.id === undefined || r.id === null) { return; }
            var sid = String(r.id);
            if (!/^\d+$/.test(sid)) { return; }
            var body = document.getElementById('readers-body');
            if (!body) { return; }
            var row = body.querySelector('tr[data-reader-row="' + sid + '"]');
            if (row) {
                repaintReaderRow(r);
            } else {
                var empty = document.getElementById('readers-empty');
                if (empty) { empty.remove(); }
                row = buildReaderRow(r);
                body.insertBefore(row, body.firstChild);
            }
            row.classList.remove('js-row-flash');
            void row.offsetWidth; // restart the flash animation
            row.classList.add('js-row-flash');
        }

        document.addEventListener('realtime:roster', function (e) {
            var update = e.detail || {};
            if (update.type === 'reader_deleted') {
                var gone = update.payload || {};
                if (gone.id !== undefined) { removeReaderRow(gone.id); }
                return;
            }
            if (update.type !== 'reader_updated' && update.type !== 'reader_created') { return; }
            applyReader(update.payload || {});
        });

        // Floating ⋯ menu: pin the open popover under its button
        // (position:fixed + viewport coords — no anchor-positioning
        // dependency, works everywhere popover itself does).
        document.getElementById('readers-body').addEventListener('toggle', function (e) {
            var pop = e.target && e.target.closest ? e.target.closest('.reader-menu') : null;
            if (!pop || !pop.matches || !pop.matches(':popover-open')) { return; }
            var btn = document.querySelector('[popovertarget="' + pop.id + '"]');
            if (!btn) { return; }
            var r = btn.getBoundingClientRect();
            pop.style.top = (r.bottom + 4) + 'px';
            pop.style.left = Math.max(8, r.right - pop.offsetWidth) + 'px';
        }, true);

        var resultBox = document.getElementById('reader-result');

        // Save one reader (label + mode) — delegated so live-prepended
        // rows save exactly like server-rendered ones.
        document.getElementById('readers-body').addEventListener('click', function (e) {
            var saveBtn = e.target && e.target.closest ? e.target.closest('.reader-save') : null;
            var rotateBtn = e.target && e.target.closest ? e.target.closest('.reader-rotate') : null;
            var deleteBtn = e.target && e.target.closest ? e.target.closest('.reader-delete') : null;
            // Picking a menu item closes the floating menu first, so
            // the confirm modal never opens underneath it. (A click can
            // only land inside an open popover, so no state guard needed.)
            var menu = e.target && e.target.closest ? e.target.closest('.reader-menu') : null;
            if (menu) { menu.hidePopover(); }

            if (saveBtn) {
                var id = saveBtn.dataset.reader;
                var labelEl = document.getElementById('label-' + id);
                var modeEl = document.getElementById('mode-' + id);
                // Finding 12: a row deleted mid-click must no-op, never throw.
                if (!labelEl || !modeEl) { return; }
                var label = labelEl.value.trim();
                var mode = modeEl.value;
                busy(saveBtn, true);
                postJson('/api/v1/admin/readers/' + id, {label: label, active_event_type: mode}, 'PUT')
                    .then(function (r) {
                        busy(saveBtn, false);
                        show(resultBox,
                            r.ok ? READER_UPDATED_MSG
                                 : (r.data && r.data.message) || ERROR_GENERIC_MSG,
                            r.ok);
                        if (r.ok) {
                            document.getElementById('label-' + id).dataset.original = label;
                            if (window.PulseToast) { PulseToast.success(TOAST_READER_SAVED); }
                        } else if (window.PulseToast) {
                            PulseToast.error(TOAST_READER_SAVE_FAILED, (r.data && r.data.message) || '');
                        }
                    }).catch(function () {
                        busy(saveBtn, false);
                        show(resultBox, ERROR_GENERIC_MSG, false);
                        if (window.PulseToast) { PulseToast.error(TOAST_NETWORK); }
                    });
                return;
            }

            // TASK-034 — rotate the key behind the Datum confirm modal
            // (the deployed reader bricks until re-flashed). The new key
            // renders EXACTLY ONCE, in this result box.
            if (rotateBtn) {
                window.DatumConfirm.open('reader-confirm', {
                    title: ROTATE_LABEL,
                    message: ROTATE_CONFIRM.replace(':LABEL:', rotateBtn.dataset.label || ''),
                    confirmLabel: ROTATE_LABEL,
                    onConfirm: function () {
                        busy(rotateBtn, true);
                        postJson('/api/v1/admin/readers/' + rotateBtn.dataset.reader + '/rotate-key', {})
                            .then(function (r) {
                                busy(rotateBtn, false);
                                var text = (r.data && r.data.message) || ERROR_GENERIC_MSG;
                                if (r.ok && r.data && r.data.api_key) {
                                    text += '\n' + r.data.api_key_notice + '\n' + r.data.api_key;
                                }
                                show(resultBox, text, r.ok);
                                if (r.ok) {
                                    if (window.PulseToast) { PulseToast.success(TOAST_KEY_ROTATED); }
                                } else if (window.PulseToast) {
                                    PulseToast.error(ERROR_GENERIC_MSG, (r.data && r.data.message) || '');
                                }
                            }).catch(function () {
                                busy(rotateBtn, false);
                                show(resultBox, ERROR_GENERIC_MSG, false);
                                if (window.PulseToast) { PulseToast.error(TOAST_NETWORK); }
                            });
                    }
                });
            }
            if (deleteBtn) {
                window.DatumConfirm.open('reader-confirm', {
                    title: DELETE_LABEL,
                    message: DELETE_CONFIRM.replace(':LABEL:', deleteBtn.dataset.label || ''),
                    confirmLabel: DELETE_LABEL,
                    onConfirm: function () {
                        busy(deleteBtn, true);
                        postJson('/api/v1/admin/readers/' + deleteBtn.dataset.reader, {}, 'DELETE')
                            .then(function (r) {
                                busy(deleteBtn, false);
                                show(resultBox, r.ok ? READER_DELETED_MSG : (r.data && r.data.message) || ERROR_GENERIC_MSG, r.ok);
                                if (r.ok) {
                                    removeReaderRow(deleteBtn.dataset.reader);
                                    if (window.PulseToast) { PulseToast.success(TOAST_READER_DELETED); }
                                } else if (window.PulseToast) {
                                    PulseToast.error(ERROR_GENERIC_MSG, (r.data && r.data.message) || '');
                                }
                            }).catch(function () {
                                busy(deleteBtn, false);
                                show(resultBox, ERROR_GENERIC_MSG, false);
                                if (window.PulseToast) { PulseToast.error(TOAST_NETWORK); }
                            });
                    }
                });
            }
        });

        // Create one reader — the key comes back exactly once, and the
        // row goes live immediately (fetch-first, WS replay no-ops).
        var createForm = document.getElementById('reader-create-form');
        createForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = createForm.querySelector('button[type="submit"]');
            var box = document.getElementById('reader-create-result');
            busy(btn, true);
            postJson('/api/v1/admin/readers', {
                label: document.getElementById('reader-name-new').value.trim(),
                type: document.getElementById('reader-type-new').value,
                active_event_type: document.getElementById('reader-mode-new').value
            }).then(function (r) {
                busy(btn, false);
                var text = (r.data && r.data.message) || ERROR_GENERIC_MSG;
                if (r.ok && r.data && r.data.api_key) {
                    text += '\n' + r.data.api_key_notice + '\n' + r.data.api_key;
                }
                show(box, text, r.ok);
                if (r.ok) {
                    if (r.data && r.data.reader) { applyReader(r.data.reader); }
                    createForm.reset();
                    if (window.PulseToast) { PulseToast.success(TOAST_READER_CREATED); }
                } else if (window.PulseToast) {
                    PulseToast.error(ERROR_GENERIC_MSG, (r.data && r.data.message) || '');
                }
            }).catch(function () {
                busy(btn, false);
                show(box, ERROR_GENERIC_MSG, false);
                if (window.PulseToast) { PulseToast.error(TOAST_NETWORK); }
            });
        });
    })();
</script>
@endsection
