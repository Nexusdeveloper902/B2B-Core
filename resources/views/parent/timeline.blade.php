@extends('layouts.app')

@section('title', __('app.parent_timeline', ['name' => $student->name]))

@section('content')
<div class="page-head">
    <h1>{{ __('app.parent_timeline', ['name' => $student->name]) }}</h1>
    <p class="muted">
        {{ $student->schoolClass?->name }} ·
        {{ $student->pae_enrolled ? 'PAE ✓' : '—' }} ·
        {{ __('app.points') }}: <strong>{{ $points }}</strong>
    </p>
    <p class="muted small">Simplified stand-in view (selected by staff) — a real parent-auth system is
        intentionally out of scope for this phase. / Vista de sustitución simplificada (seleccionada por
        personal) — un sistema real de autenticación de padres está fuera del alcance de esta fase.</p>
</div>

<section class="card">
    @if(empty($timeline))
        <p class="muted">{{ __('app.no_events') }}</p>
    @else
        <table class="table">
            <thead>
            <tr>
                <th>{{ __('app.event_type') }}</th>
                <th>{{ __('app.tapped_at') }}</th>
                <th>{{ __('app.reader_label') }}</th>
                <th>{{ __('app.material') }}</th>
                <th>{{ __('app.points') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($timeline as $event)
                <tr>
                    <td><code>{{ $event['type'] }}</code></td>
                    <td>{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('Y-m-d H:i') }}</td>
                    <td>{{ $event['reader'] ?? '—' }}</td>
                    <td>{{ $event['material'] ?? '—' }}</td>
                    <td>{{ $event['points'] !== null ? '+'.$event['points'] : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection
