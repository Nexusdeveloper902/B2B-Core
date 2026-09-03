@extends('layouts.app')

@section('title', __('app.teacher_dashboard'))

@section('content')
<div class="page-head">
    <h1>{{ __('app.teacher_dashboard') }} — {{ __('app.today_attendance') }}</h1>
    <p class="page-meta">
        <span>{{ __('app.late_cutoff_note', ['cutoff' => $cutoff]) }}</span>
    </p>
</div>

<div class="stack">
    @if($classes->isEmpty())
        <x-empty>{{ __('app.no_students') }}</x-empty>
    @else
        @foreach($classes as $class)
            @php($rows = $attendanceByClass[$class->id])
            <x-panel :label="__('app.class')" rule>
                <h2>{{ $class->name }}</h2>
                @if($class->teacher)
                    <p class="panel-sub">{{ $class->teacher->name }}</p>
                @endif

                <div class="ledger-wrap">
                    <table class="ledger-table">
                        <thead>
                        <tr>
                            <th scope="col">{{ __('app.student') }}</th>
                            <th scope="col">{{ __('app.status') }}</th>
                            <th scope="col">{{ __('app.tapped_at') }}</th>
                            <th scope="col">{{ __('app.pae_enrolled') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td>
                                    <span class="student-cell">
                                        {{ $row['student']->name }}
                                        <a class="tiny-link" href="{{ route('parent.timeline', $row['student']) }}">
                                            {{ __('app.view_parent') }}
                                        </a>
                                    </span>
                                </td>
                                <td>
                                    <x-stamp :status="$row['status']">{{ __('app.'.$row['status']) }}</x-stamp>
                                </td>
                                <td class="num">{{ $row['tappedAt'] ?? '—' }}</td>
                                <td class="num">{{ $row['student']->pae_enrolled ? '✓' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted">{{ __('app.no_students') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endforeach
    @endif
</div>
@endsection
