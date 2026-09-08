{{--
    TASK-027 — the reader management desk (/admin/readers): rename a
    reader and switch its active mode in one update. The dashboard's
    quick mode-form stays; this is the full management surface (gap
    closed: "change a reader's mode and name"). Writes go to
    PUT /api/v1/admin/readers/{id} (label + active_event_type).
--}}
@extends('layouts.app')

@section('title', __('app.readers_page'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.readers_page') }}</span>
    <h1>{{ __('app.readers_page') }}</h1>
    <p class="lede-sub">{{ __('app.readers_page_sub') }}</p>
</div>

<section data-reveal>
    <x-panel :label="__('app.readers')" rule>
        @if($readers->isEmpty())
            <x-empty>{{ __('app.no_readers') }}</x-empty>
        @else
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
                    <tbody>
                    @foreach($readers as $reader)
                        <tr data-reader-row="{{ $reader->id }}">
                            <td data-label="{{ __('app.reader_name') }}">
                                <input type="text" class="bare-input reader-label"
                                       value="{{ $reader->label }}" data-original="{{ $reader->label }}"
                                       aria-label="{{ __('app.reader_name') }}"
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
                            <td data-label="">
                                <button type="button" class="btn btn-primary btn-small reader-save"
                                        data-reader="{{ $reader->id }}">
                                    {{ __('app.save_reader') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div id="reader-result" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>
</section>

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

        function busy(btn, on) {
            btn.disabled = on;
            btn.classList.toggle('is-loading', on);
        }

        function show(el, text, ok) {
            el.classList.remove('hidden');
            el.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
            el.textContent = text;
        }

        var resultBox = document.getElementById('reader-result');

        // TASK-029 — live reader rows: a reader_updated roster frame (or
        // the hello snapshot replay) repaints the row — unless this admin
        // is typing into that exact input (never clobber the user).
        document.addEventListener('realtime:roster', function (e) {
            var update = e.detail || {};
            if (update.type !== 'reader_updated') { return; }
            var r = update.payload || {};
            if (r.id === undefined) { return; }
            var label = document.getElementById('label-' + r.id);
            if (label && document.activeElement !== label && r.label !== undefined) {
                label.value = r.label;
                label.dataset.original = r.label;
            }
            var mode = document.getElementById('mode-' + r.id);
            if (mode && document.activeElement !== mode && r.active_event_type !== undefined) {
                mode.value = r.active_event_type;
            }
        });

        // Save one reader (label + mode) via the settings endpoint.
        document.querySelectorAll('.reader-save').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.dataset.reader;
                var label = document.getElementById('label-' + id).value.trim();
                var mode = document.getElementById('mode-' + id).value;
                busy(btn, true);
                fetch('/api/v1/admin/readers/' + id, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({label: label, active_event_type: mode})
                }).then(function (r) {
                    return r.json().then(function (data) { return {ok: r.ok, data: data}; });
                }).then(function (r) {
                    busy(btn, false);
                    show(resultBox,
                        r.ok ? '{{ __('app.reader_updated') }}'
                             : (r.data && r.data.message) || '{{ __('app.error_generic') }}',
                        r.ok);
                    if (r.ok) {
                        document.getElementById('label-' + id).dataset.original = label;
                    }
                }).catch(function () { busy(btn, false); });
            });
        });
    })();
</script>
@endsection
