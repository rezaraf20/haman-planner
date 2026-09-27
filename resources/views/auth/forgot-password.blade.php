@extends('auth.layout')
@section('title', __('auth.forgot_title'))
@section('subtitle', __('auth.forgot_subtitle'))
@section('content')
<form method="post" action="{{ route('password.email') }}">
@csrf
<div class="field"><label for="email">{{ __('auth.email') }}</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus></div>
<button class="btn">{{ __('auth.forgot_button') }}</button>
</form>
<div class="foot"><a href="{{ route('login') }}">{{ __('auth.back_to_login') }}</a></div>
@endsection
