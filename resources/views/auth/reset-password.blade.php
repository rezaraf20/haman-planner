@extends('auth.layout')
@section('title', __('auth.reset_title'))
@section('subtitle', __('auth.reset_subtitle'))
@section('content')
<form method="post" action="{{ route('password.update') }}">
@csrf
<input type="hidden" name="token" value="{{ $token }}">
<div class="field"><label for="email">{{ __('auth.email') }}</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email', $email) }}" required></div>
<div class="field"><label for="password">{{ __('auth.new_password') }}</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required autofocus><div class="help">{{ __('auth.password_help') }}</div></div>
<div class="field"><label for="password_confirmation">{{ __('auth.new_password_confirm') }}</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></div>
<button class="btn">{{ __('auth.reset_button') }}</button>
</form>
<div class="foot"><a href="{{ route('login') }}">{{ __('auth.back_to_login') }}</a></div>
@endsection
