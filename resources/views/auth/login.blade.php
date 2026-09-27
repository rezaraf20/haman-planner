@extends('auth.layout')
@section('title', __('auth.login_title'))
@section('subtitle', __('auth.login_subtitle'))
@section('content')
<form method="post" action="{{ route('login.submit') }}">
@csrf
<div class="field"><label for="email">{{ __('auth.email') }}</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div>
<div class="field"><label for="password">{{ __('auth.password_label') }}</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
<div class="row">
<label class="remember"><input type="checkbox" name="remember" value="1"> {{ __('auth.remember') }}</label>
<a href="{{ route('password.request') }}">{{ __('auth.forgot_link') }}</a>
</div>
<button class="btn">{{ __('auth.login_button') }}</button>
</form>
@if (\App\Http\Controllers\RegisterController::enabled())
<div class="foot">{{ __('auth.no_account') }} <a href="{{ route('register') }}">{{ __('auth.register_link') }}</a></div>
@endif
@endsection
