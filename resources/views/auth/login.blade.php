@extends('auth.layout')
@section('title', 'ورود')
@section('subtitle', 'به حساب Planner خود وارد شوید')
@section('content')
<form method="post" action="{{ route('login.submit') }}">
@csrf
<div class="field"><label for="email">ایمیل</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div>
<div class="field"><label for="password">رمز عبور</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
<div class="row">
<label class="remember"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label>
<a href="{{ route('password.request') }}">فراموشی رمز عبور؟</a>
</div>
<button class="btn">ورود به Planner</button>
</form>
@if (\App\Http\Controllers\RegisterController::enabled())
<div class="foot">حساب ندارید؟ <a href="{{ route('register') }}">ثبت‌نام رایگان</a></div>
@endif
@endsection
