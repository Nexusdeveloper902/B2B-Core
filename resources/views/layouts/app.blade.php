<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.app_name')) — {{ __('app.app_name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<a class="skip" href="#main">{{ __('app.skip_to_content') }}</a>

<header class="topbar">
    <div class="shell topbar-in">
        <a class="wordmark" href="{{ auth()->check() ? route('dashboard') : route('login') }}"
           aria-label="{{ __('app.app_name') }}">
            <span class="wordmark-tap" aria-hidden="true"></span>
            <span class="wordmark-name">Presence<em>Platform</em></span>
        </a>

        @auth
            <nav class="topnav" aria-label="{{ __('app.primary_nav') }}">
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}"
                       @class(['is-active' => request()->routeIs('admin.dashboard')])>
                        {{ __('app.admin_dashboard') }}
                    </a>
                    <a href="{{ route('admin.pairing') }}"
                       @class(['is-active' => request()->routeIs('admin.pairing')])>
                        {{ __('app.pairing_desk') }}
                    </a>
                @endif
                <a href="{{ route('teacher.dashboard') }}"
                   @class(['is-active' => request()->routeIs('teacher.dashboard') || request()->routeIs('dashboard')])>
                    {{ __('app.teacher_dashboard') }}
                </a>
            </nav>
        @endauth

        <div class="topbar-tools">
            @auth
                <span class="topbar-user">{{ __('app.welcome', ['name' => auth()->user()->name]) }}</span>

                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="linklike">{{ __('app.logout') }}</button>
                </form>
            @endauth

            <nav class="langswitch" aria-label="{{ __('app.language') }}">
                <a href="{{ route('locale.switch', 'en') }}"
                   @class(['is-active' => app()->getLocale() === 'en'])
                   @if(app()->getLocale() === 'en') aria-current="true" @endif>EN</a>
                <span class="langswitch-sep" aria-hidden="true">/</span>
                <a href="{{ route('locale.switch', 'es') }}"
                   @class(['is-active' => app()->getLocale() === 'es'])
                   @if(app()->getLocale() === 'es') aria-current="true" @endif>ES</a>
            </nav>
        </div>
    </div>
</header>

<main id="main" class="main">
    <div class="shell">
        @if (session('status'))
            <div class="notice notice-ok" role="status">{{ session('status') }}</div>
        @endif

        @yield('content')
    </div>
</main>

<footer class="footer">
    <div class="shell">
        <div class="footer-in">
            <a class="wordmark" href="{{ auth()->check() ? route('dashboard') : route('login') }}">
                <span class="wordmark-tap" aria-hidden="true"></span>
                <span class="wordmark-name">Presence<em>Platform</em></span>
            </a>
            <p class="footer-note">{{ __('app.footer_note') }}</p>
        </div>
        <div class="footer-legal">
            <p>Presence Platform — Core · EN/ES · {{ __('app.env') }} <code>{{ config('app.env') }}</code></p>
        </div>
    </div>
</footer>
</body>
</html>
