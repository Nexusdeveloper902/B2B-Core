@extends('layouts.app')

@section('title', __('app.login'))

@section('content')
<div class="card auth-card">
    <h1>{{ __('app.login_title') }}</h1>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="email">{{ __('app.email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
               autocomplete="email" placeholder="admin@presence.test">

        <label for="password">{{ __('app.password') }}</label>
        <input id="password" type="password" name="password" required autocomplete="current-password"
               placeholder="password">

        @error('email')
            <p class="field-error">{{ $message }}</p>
        @enderror

        <button type="submit" class="btn btn-primary btn-block">{{ __('app.login') }}</button>
    </form>

    <p class="hint">{{ __('app.login_hint') }}</p>
</div>
@endsection
