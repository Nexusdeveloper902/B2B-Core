@extends('layouts.app')

{{--
    TASK-030-A (ADR-044) — first-login password rotation. Flagged users
    (auto-provisioned student accounts) land here on every page until
    they rotate the shared initial password; everyone else may use the
    same form voluntarily. Same auth-card grammar as the login view:
    POST contract untouched (PUT /password/change + @csrf +
    #current_password/#password/#password_confirmation).
--}}

@section('title', __('app.password_change_title'))

@section('content')
<div class="auth-wrap">
    <section class="panel auth-card">
        <div class="auth-band">
            <span class="wordmark-tap" aria-hidden="true"></span>
            <div>
                <p class="auth-band-title">{{ __('app.app_name') }}</p>
                <p class="auth-band-sub">{{ __('app.password_change_band_sub') }}</p>
            </div>
            <span class="material-symbols-outlined is-16" aria-hidden="true" style="margin-left:auto; color: var(--text-meta);">key</span>
        </div>

        <div class="auth-body">
            <h1>{{ __('app.password_change_title') }}</h1>
            <p class="panel-sub">{{ __('app.password_change_subtitle') }}</p>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                @method('PUT')

                <x-field :label="__('app.current_password')" for="current_password">
                    <input id="current_password" type="password" name="current_password" required
                           autocomplete="current-password" placeholder="••••••••"
                           @error('current_password') aria-invalid="true" aria-describedby="current_password-error" @enderror>
                    @error('current_password')
                        <p class="field-error" id="current_password-error">{{ $message }}</p>
                    @enderror
                </x-field>

                <x-field :label="__('app.new_password')" for="password">
                    <input id="password" type="password" name="password" required
                           autocomplete="new-password" placeholder="••••••••"
                           @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')
                        <p class="field-error" id="password-error">{{ $message }}</p>
                    @enderror
                </x-field>

                <x-field :label="__('app.confirm_password')" for="password_confirmation">
                    <input id="password_confirmation" type="password" name="password_confirmation" required
                           autocomplete="new-password" placeholder="••••••••">
                </x-field>

                <button type="submit" class="btn btn-primary btn-block">
                    {{ __('app.save_password') }}
                    <span class="material-symbols-outlined is-16" aria-hidden="true">arrow_forward</span>
                </button>
            </form>
        </div>
    </section>
</div>
@endsection
