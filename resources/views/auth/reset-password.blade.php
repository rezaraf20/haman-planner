@extends('auth.layout')
@section('title', 'تعیین رمز جدید')
@section('subtitle', 'رمز عبور جدید حساب خود را وارد کنید.')
@section('content')
<form method="post" action="{{ route('password.update') }}">
@csrf
<input type="hidden" name="token" value="{{ $token }}">
<div class="field"><label for="email">ایمیل</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email', $email) }}" required></div>
<div class="field"><label for="password">رمز عبور جدید</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required autofocus><div class="help">حداقل ۸ کاراکتر، شامل حرف و عدد</div></div>
<div class="field"><label for="password_confirmation">تکرار رمز عبور جدید</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></div>
<button class="btn">ذخیره رمز جدید</button>
</form>
<div class="foot"><a href="{{ route('login') }}">بازگشت به ورود</a></div>
@endsection
