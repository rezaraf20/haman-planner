@extends('auth.layout')
@section('title', 'فراموشی رمز عبور')
@section('subtitle', 'ایمیل حساب خود را وارد کنید تا لینک تعیین رمز جدید برایتان ارسال شود. اگر Telegram به حسابتان متصل باشد، لینک در ربات هم ارسال می‌شود.')
@section('content')
<form method="post" action="{{ route('password.email') }}">
@csrf
<div class="field"><label for="email">ایمیل</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus></div>
<button class="btn">ارسال لینک بازیابی</button>
</form>
<div class="foot"><a href="{{ route('login') }}">بازگشت به ورود</a></div>
@endsection
