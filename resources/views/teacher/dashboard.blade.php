@extends('layouts.app')

@section('title', __('app.teacher_dashboard'))

@section('content')
<div class="page-head">
    <h1>{{ __('app.teacher_dashboard') }} — {{ __('app.today_attendance') }}</h1>
    <p class="muted">{{ __('app.late_cutoff_note', ['cutoff' => $cutoff]) }}</p>
</div>

@if($classes->isEmpty())
    <div class="card"><p class="muted">{{ __('app.no_students') }}</p></div>
@else
    @foreach($classes as $class)
        @php($rows = $attendanceByClass[$class->id])
        <section class="card">
            <h2>{{ __('app.class') }}: {{ $class->name }}
                @if($class->teacher) <span class="muted">· {{ $class->teacher->name }}</span> @endif
            </h2>

            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('app.student') }}</th>
                    <th>{{ __('app.status') }}</th>
                    <th>{{ __('app.tapped_at') }}</th>
                    <th>{{ __('app.pae_enrolled') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>
                            {{ $row['student']->name }}
                            <a class="tiny-link" href="{{ route('parent.timeline', $row['student']) }}">
                                {{ __('app.view_parent') }} ↗
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-{{ $row['status'] }}">
                                {{ __('app.'.$row['status']) }}
                            </span>
                        </td>
                        <td>{{ $row['tappedAt'] ?? '—' }}</td>
                        <td>{{ $row['student']->pae_enrolled ? '✓' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('app.no_students') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endforeach
@endif
@endsection
