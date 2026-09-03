@extends('layouts.app')

@section('title', __('app.login'))

@section('content')
<div class="auth-wrap">
    <section class="panel panel-rule">
        <p class="panel-label"><span class="dot" aria-hidden="true"></span>{{ __('app.app_name') }}</p>
        <h1>{{ __('app.login_title') }}</h1>

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <x-field :label="__('app.email')" for="email">
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                       autocomplete="email" placeholder="admin@presence.test">
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </x-field>

            <x-field :label="__('app.password')" for="password">
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       placeholder="password">
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </x-field>

            <button type="submit" class="btn btn-primary btn-block">{{ __('app.login') }}</button>
        </form>
    </section>

    <div class="auth-aside">
        <dl>
            <dt>{{ __('app.demo_credentials') }}</dt>
            <dd>admin@presence.test</dd>
            <dd>teacher@presence.test</dd>
            <dd>{{ __('app.password') }}: password</dd>
        </dl>
    </div>
</div>
@endsection
