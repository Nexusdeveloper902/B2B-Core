{{-- UI polish pass — 403/404/500 used to render Laravel's bare abort
     page: no shell, no nav, no way out. These views render INSIDE the
     app layout (topbar keeps role-scoped links; guests get the login
     link) so the way back is always on screen. --}}
@extends('layouts.app')

@section('title', __('app.error_'.$code.'_title').' · '.__('app.app_name'))

@section('content')
<div class="error-page">
    <p class="error-code">{{ $code }}</p>
    <h1>{{ __('app.error_'.$code.'_title') }}</h1>
    <p class="lede-sub">{{ __('app.error_'.$code.'_text') }}</p>
    <div class="error-actions">
        @auth
            <a class="btn btn-primary" href="{{ auth()->user()->isStudent() ? route('student.dashboard') : route('dashboard') }}">
                {{ __('app.error_back') }}
            </a>
        @else
            <a class="btn btn-primary" href="{{ route('login') }}">{{ __('app.error_home') }}</a>
        @endauth
    </div>
</div>
@endsection
