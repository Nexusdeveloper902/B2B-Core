@extends('layouts.app')

{{--
    TASK-026 — mockup "Sign In — Presence Platform": architectural auth
    card, context band, icon inputs, seeded-credential chips. The real
    form contract is untouched: POST /login + @csrf + #email/#password/
    #pw-toggle + demo chips autofill + validation errors. Mockup parts
    with no backing functionality (FORGOT KEY link, SSO gateway banner,
    TLS/FERPA/ISO badges, build number) are deliberately absent — see
    docs/FRONTEND.md gap ledger.
--}}

@section('title', __('app.login'))

@section('content')
<div class="auth-wrap">
    <section class="panel auth-card">
        <div class="auth-band">
            <span class="wordmark-tap" aria-hidden="true"></span>
            <div>
                <p class="auth-band-title">{{ __('app.app_name') }}</p>
                <p class="auth-band-sub">{{ __('app.login_band_sub') }}</p>
            </div>
            <span class="material-symbols-outlined is-16" aria-hidden="true" style="margin-left:auto; color: var(--text-meta);">verified_user</span>
        </div>

        <div class="auth-body">
            <h1>{{ __('app.login_title') }}</h1>
            <p class="panel-sub">{{ __('app.login_subtitle') }}</p>

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <x-field :label="__('app.email')" for="email">
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                           autocomplete="email" placeholder="admin@presence.test"
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')
                        <p class="field-error" id="email-error">{{ $message }}</p>
                    @enderror
                </x-field>

                <x-field :label="__('app.password')" for="password">
                    <div class="pw-wrap">
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                               placeholder="••••••••"
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        <button type="button" class="pw-toggle" id="pw-toggle" data-show="{{ __('app.show') }}"
                                data-hide="{{ __('app.hide') }}" aria-pressed="false">{{ __('app.show') }}</button>
                    </div>
                    @error('password')
                        <p class="field-error" id="password-error">{{ $message }}</p>
                    @enderror
                </x-field>

                <button type="submit" class="btn btn-primary btn-block">
                    {{ __('app.login') }}
                    <span class="material-symbols-outlined is-16" aria-hidden="true">arrow_forward</span>
                </button>
            </form>
        </div>
    </section>

    {{-- Seeded access (mockup "01 // SEEDED ACCESS"): the real demo
         credentials, one tap to fill — same TASK-017 script below. --}}
    <div class="auth-aside" data-reveal>
        <dl>
            <dt>{{ __('app.demo_credentials') }}</dt>
            <dd>
                <div class="demo-chips">
                    <button type="button" class="demo-chip" data-email="admin@presence.test"
                            title="{{ __('app.demo_chip_hint') }}">
                        <span class="chip-role">{{ __('app.role_admin') }}</span>admin@presence.test
                    </button>
                    <button type="button" class="demo-chip" data-email="teacher@presence.test"
                            title="{{ __('app.demo_chip_hint') }}">
                        <span class="chip-role">{{ __('app.role_teacher') }}</span>teacher@presence.test
                    </button>
                </div>
            </dd>
            <dd>{{ __('app.password') }}: <code>password</code></dd>
        </dl>
    </div>
</div>

<script>
    (function () {
        // TASK-017 — smooth sign-in affordances: password reveal and
        // one-tap demo credential fill. Pure vanilla, no dependencies.
        var toggle = document.getElementById('pw-toggle');
        if (toggle) {
            var pw = document.getElementById('password');
            toggle.addEventListener('click', function () {
                var reveal = pw.type === 'password';
                pw.type = reveal ? 'text' : 'password';
                toggle.textContent = reveal ? toggle.dataset.hide : toggle.dataset.show;
                toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            });
        }

        document.querySelectorAll('.demo-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var email = document.getElementById('email');
                var pass = document.getElementById('password');
                email.value = chip.dataset.email;
                pass.value = 'password';
                pass.focus();
            });
        });
    })();
</script>
@endsection
