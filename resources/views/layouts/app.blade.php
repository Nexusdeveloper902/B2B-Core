<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.app_name')) — {{ __('app.app_name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    {{-- Versioned asset URLs (filemtime): browsers heuristically cache
         unversioned CSS/JS for days — without this, a stylesheet pass
         like TASK-032 is invisible until a force-refresh. Each file
         busts only when IT changes. --}}
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}?v={{ @filemtime(public_path('css/tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    {{-- Motion gate (Signal, marketplace pattern): flags motion availability
         before first paint so reveal states never flash. Never adds the flag
         under prefers-reduced-motion. --}}
    <script>
        try {
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.documentElement.classList.add('js-motion');
            }
        } catch (e) { /* no motion flags without JS APIs */ }
    </script>
    <script type="module" src="{{ asset('js/motion.js') }}?v={{ @filemtime(public_path('js/motion.js')) }}"></script>
</head>
<body>
<a class="skip" href="#main">{{ __('app.skip_to_content') }}</a>

{{-- TASK-026 — mockup "Architectural Ambient Layer & Top Bar": fixed,
     blurred, pill nav, avatar chip, icon logout. Role scoping (the real
     nav grammar) is unchanged; only the surface changed. --}}
<header class="topbar">
    <div class="shell topbar-in">
        <a class="wordmark" href="{{ auth()->check() ? (auth()->user()->isStudent() ? route('student.dashboard') : route('dashboard')) : route('login') }}"
           aria-label="{{ __('app.app_name') }}">
            <span class="wordmark-tap" aria-hidden="true"></span>
            <span class="wordmark-name">Presence<em>Platform</em></span>
        </a>

        @auth
            <nav class="topnav" aria-label="{{ __('app.primary_nav') }}">
                @if(auth()->user()->isStudent())
                    {{-- TASK-025 — student self-service nav (own data only);
                         TASK-026 adds the standings page (same scoping). --}}
                    <a href="{{ route('student.dashboard') }}"
                       @class(['is-active' => request()->routeIs('student.dashboard')])>
                        {{ __('app.student_dashboard') }}
                    </a>
                    <a href="{{ route('student.history') }}"
                       @class(['is-active' => request()->routeIs('student.history')])>
                        {{ __('app.student_history') }}
                    </a>
                    <a href="{{ route('student.rewards') }}"
                       @class(['is-active' => request()->routeIs('student.rewards')])>
                        {{ __('app.student_rewards') }}
                    </a>
                    <a href="{{ route('student.leaderboard') }}"
                       @class(['is-active' => request()->routeIs('student.leaderboard')])>
                        {{ __('app.student_standings') }}
                    </a>
                @else
                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('admin.dashboard') }}"
                           @class(['is-active' => request()->routeIs('admin.dashboard')])>
                            {{ __('app.admin_dashboard') }}
                        </a>
                        {{-- TASK-028 — the TASK-027 desks were reachable only
                             by URL; the nav now links them (workflow order:
                             people, hardware, card links, eco monitor). --}}
                        <a href="{{ route('admin.students') }}"
                           @class(['is-active' => request()->routeIs('admin.students')])>
                            {{ __('app.students_page') }}
                        </a>
                        <a href="{{ route('admin.readers') }}"
                           @class(['is-active' => request()->routeIs('admin.readers')])>
                            {{ __('app.readers_page') }}
                        </a>
                        <a href="{{ route('admin.pairing') }}"
                           @class(['is-active' => request()->routeIs('admin.pairing')])>
                            {{ __('app.pairing_desk') }}
                        </a>
                        <a href="{{ route('admin.ecostation') }}"
                           @class(['is-active' => request()->routeIs('admin.ecostation')])>
                            {{ __('app.ecostation') }}
                        </a>
                    @endif
                    <a href="{{ route('teacher.dashboard') }}"
                       @class(['is-active' => request()->routeIs('teacher.dashboard') || request()->routeIs('dashboard')])>
                        {{ __('app.teacher_dashboard') }}
                    </a>
                @endif
            </nav>
        @endauth

        <div class="topbar-tools">
            @auth
                @php($nameParts = explode(' ', trim(auth()->user()->name)))
                @php($initials = strtoupper(mb_substr($nameParts[0] ?? '·', 0, 1) . mb_substr($nameParts[1] ?? '', 0, 1)))
                <span class="topbar-user" title="{{ auth()->user()->email }}">
                    <span class="user-avatar" aria-hidden="true">{{ $initials }}</span>
                    <span class="topbar-user-name" data-greeting="{{ __('app.welcome_short') }}">{{ auth()->user()->name }}</span>
                </span>

                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="logout-btn" title="{{ __('app.logout') }}">
                        <span class="material-symbols-outlined is-18" aria-hidden="true">logout</span>
                        <span class="logout-text">{{ __('app.logout') }}</span>
                    </button>
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

            {{-- Mobile menu (no JS: native details — marketplace pattern) --}}
            @auth
                <details class="mobilenav">
                    <summary aria-label="{{ __('app.primary_nav') }}">
                        <span class="mobilenav-bars" aria-hidden="true"><i></i><i></i><i></i></span>
                    </summary>
                    <div class="mobilenav-body">
                        <nav class="mobilenav-links" aria-label="{{ __('app.primary_nav') }}">
                            @if(auth()->user()->isStudent())
                                <a href="{{ route('student.dashboard') }}">{{ __('app.student_dashboard') }}</a>
                                <a href="{{ route('student.history') }}">{{ __('app.student_history') }}</a>
                                <a href="{{ route('student.rewards') }}">{{ __('app.student_rewards') }}</a>
                                <a href="{{ route('student.leaderboard') }}">{{ __('app.student_standings') }}</a>
                            @else
                                @if(auth()->user()->isAdmin())
                                    <a href="{{ route('admin.dashboard') }}">{{ __('app.admin_dashboard') }}</a>
                                    <a href="{{ route('admin.students') }}">{{ __('app.students_page') }}</a>
                                    <a href="{{ route('admin.readers') }}">{{ __('app.readers_page') }}</a>
                                    <a href="{{ route('admin.pairing') }}">{{ __('app.pairing_desk') }}</a>
                                    <a href="{{ route('admin.ecostation') }}">{{ __('app.ecostation') }}</a>
                                @endif
                                <a href="{{ route('teacher.dashboard') }}">{{ __('app.teacher_dashboard') }}</a>
                            @endif
                        </nav>
                        <form method="POST" action="{{ route('logout') }}" class="inline-form">
                            @csrf
                            <button type="submit" class="linklike">{{ __('app.logout') }}</button>
                        </form>
                    </div>
                </details>
            @endauth
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
            <div class="footer-brand">
                <a class="wordmark" href="{{ auth()->check() ? (auth()->user()->isStudent() ? route('student.dashboard') : route('dashboard')) : route('login') }}">
                    <span class="wordmark-tap" aria-hidden="true"></span>
                    <span class="wordmark-name">Presence<em>Platform</em></span>
                </a>
                <p class="footer-note">{{ __('app.footer_note') }}</p>
            </div>
            {{-- Honest ops chip: the only "status" this shell can state without
                 lying is the environment it runs in (mockup's "All Systems
                 Operational" would be theater — see docs/FRONTEND.md gap #F6). --}}
            <span class="ops-chip">
                <span class="dot" aria-hidden="true"></span>
                {{ __('app.env') }}: <code>{{ config('app.env') }}</code>
            </span>
            {{-- TASK-030 (Fix 3) — project presence: the owner's Instagram
                 as a functional link (the only contact channel supplied —
                 no invented emails/phones, ever). --}}
            <span class="ops-chip">
                <span class="material-symbols-outlined is-16" aria-hidden="true">photo_camera</span>
                {{ __('app.follow_us') }}:
                <a href="https://www.instagram.com/puls.e1681?stkn=aGNnaW83MTg5OWZu&utm_source=qr"
                   target="_blank" rel="noopener" lang="en">Instagram @puls.e1681</a>
            </span>
        </div>
        <div class="footer-legal">
            <p>Presence Platform — Core · EN/ES</p>
            <p>01 // CORE RUNTIME</p>
        </div>
    </div>
</footer>
</body>
</html>
