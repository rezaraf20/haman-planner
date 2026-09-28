@extends('auth.layout')
@section('title', __('emails.unsubscribed_title'))
@section('content')
@if ($done)
<p>{{ __('emails.unsubscribed_text', ['type' => __('emails.pref.'.$preference)]) }}</p>
@else
<form method="post" action="{{ $action }}">
<p>{{ __('emails.unsubscribe') }} — {{ __('emails.pref.'.$preference) }}</p>
<button class="btn">{{ __('emails.unsubscribe') }}</button>
</form>
@endif
<div class="foot"><a href="{{ route('login') }}">{{ __('auth.back_to_login') }}</a></div>
@endsection
