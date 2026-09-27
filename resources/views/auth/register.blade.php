@extends('auth.layout')
@section('title', __('auth.register_title'))
@section('subtitle', __('auth.register_subtitle'))
@section('content')
<form method="post" action="{{ route('register.submit') }}">
@csrf
<div class="hp" aria-hidden="true"><label>Website<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>
<input type="hidden" name="timezone" id="tz" value="">
<div class="field"><label for="name">{{ __('auth.name') }}</label><input id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" maxlength="120" required autofocus></div>
<div class="field"><label for="email">{{ __('auth.email') }}</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required></div>
<div class="field"><label for="password">{{ __('auth.password_label') }}</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required><div class="help">{{ __('auth.password_help') }}</div></div>
<div class="field"><label for="password_confirmation">{{ __('auth.password_confirm') }}</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></div>
<button class="btn">{{ __('auth.register_button') }}</button>
<div class="legal">{!! __('auth.agree', [
    'terms' => '<a href="'.e(\App\Support\LocaleUrls::counterpart('marketing.terms', app()->getLocale())).'" target="_blank">'.e(__('auth.terms')).'</a>',
    'privacy' => '<a href="'.e(\App\Support\LocaleUrls::counterpart('marketing.privacy', app()->getLocale())).'" target="_blank">'.e(__('auth.privacy')).'</a>',
]) !!}</div>
</form>
<div class="foot">{{ __('auth.have_account') }} <a href="{{ route('login') }}">{{ __('auth.login_link') }}</a></div>
<script>try{document.getElementById('tz').value=Intl.DateTimeFormat().resolvedOptions().timeZone||''}catch(e){}</script>
@endsection
