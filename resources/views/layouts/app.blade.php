<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.app_name')) — {{ __('app.app_name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<nav class="topnav">
    <div class="nav-inner">
        <a href="{{ route('dashboard') }}" class="brand">
            <span class="brand-dot"></span>{{ __('app.app_name') }}
        </a>

        <div class="nav-right">
            @auth
                <span class="nav-user">{{ __('app.welcome', ['name' => auth()->user()->name]) }}</span>

                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}">{{ __('app.admin_dashboard') }}</a>
                @endif
                <a href="{{ route('teacher.dashboard') }}">{{ __('app.teacher_dashboard') }}</a>

                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="linklike">{{ __('app.logout') }}</button>
                </form>
            @endauth

            <span class="locale-switch">
                <a href="{{ route('locale.switch', 'en') }}"
                   class="{{ app()->getLocale() === 'en' ? 'active' : '' }}">EN</a>·
                <a href="{{ route('locale.switch', 'es') }}"
                   class="{{ app()->getLocale() === 'es' ? 'active' : '' }}">ES</a>
            </span>
        </div>
    </div>
</nav>

<main class="container">
    @if (session('status'))
        <div class="flash flash-ok">{{ session('status') }}</div>
    @endif

    @yield('content')
</main>

<footer class="footer">
    Presence Platform — Core · EN/ES · {{ config('app.env') }}
</footer>
</body>
</html>
