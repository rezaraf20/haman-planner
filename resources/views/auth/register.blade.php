@extends('auth.layout')
@section('title', 'ثبت‌نام')
@section('subtitle', 'یک حساب شخصی بسازید؛ داده‌های هر حساب کاملاً جدا و خصوصی است.')
@section('content')
<form method="post" action="{{ route('register.submit') }}">
@csrf
<div class="hp" aria-hidden="true"><label>Website<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>
<div class="field"><label for="name">نام</label><input id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" maxlength="120" required autofocus></div>
<div class="field"><label for="email">ایمیل</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required></div>
<div class="field"><label for="password">رمز عبور</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required><div class="help">حداقل ۸ کاراکتر، شامل حرف و عدد</div></div>
<div class="field"><label for="password_confirmation">تکرار رمز عبور</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></div>
<button class="btn">ساخت حساب</button>
</form>
<div class="foot">حساب دارید؟ <a href="{{ route('login') }}">ورود</a></div>
@endsection
