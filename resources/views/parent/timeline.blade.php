@extends('layouts.app')

@section('title', __('app.parent_timeline', ['name' => $student->name]))

@section('content')
<div class="page-head">
    <h1>{{ $student->name }}</h1>
    <p class="page-meta">
        <span>{{ __('app.parent_timeline', ['name' => $student->name]) }}</span>
    </p>
</div>

<section class="timeline-head" aria-label="{{ __('app.parent_timeline', ['name' => $student->name]) }}">
    <div class="timeline-stat">
        <span class="stat-label">{{ __('app.class') }}</span>
        <span class="stat-value">{{ $student->schoolClass?->name ?? '—' }}</span>
    </div>
    <div class="timeline-stat">
        <span class="stat-label">{{ __('app.pae_enrolled') }}</span>
        <span class="stat-value">{{ $student->pae_enrolled ? '✓' : '—' }}</span>
    </div>
    <div class="timeline-stat">
        <span class="stat-label">{{ __('app.points') }}</span>
        <span class="stat-value">{{ $points }}</span>
    </div>
</section>

<p class="scope-note">Simplified stand-in view (selected by staff) — a real parent-auth system is
    intentionally out of scope for this phase. / Vista de sustitución simplificada (seleccionada por
    personal) — un sistema real de autenticación de padres está fuera del alcance de esta fase.</p>

<div class="stack">
    <x-panel :label="__('app.event_type')" rule>
        @if(empty($timeline))
            <x-empty>{{ __('app.no_events') }}</x-empty>
        @else
            <div class="ledger-wrap">
                <table class="ledger-table">
                    <thead>
                    <tr>
                        <th scope="col">{{ __('app.event_type') }}</th>
                        <th scope="col">{{ __('app.tapped_at') }}</th>
                        <th scope="col">{{ __('app.reader_label') }}</th>
                        <th scope="col">{{ __('app.material') }}</th>
                        <th scope="col">{{ __('app.points') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($timeline as $event)
                        <tr>
                            <td><code>{{ $event['type'] }}</code></td>
                            <td class="num">{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('Y-m-d H:i') }}</td>
                            <td>{{ $event['reader'] ?? '—' }}</td>
                            <td>{{ $event['material'] ?? '—' }}</td>
                            <td class="num em">{{ $event['points'] !== null ? '+'.$event['points'] : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panel>
</div>
@endsection
