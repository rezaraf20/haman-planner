@extends('marketing.layout')
@section('meta_title', __('marketing.pricing_meta_title'))
@section('meta_description', __('marketing.pricing_meta_description'))
@push('styles')
@include('marketing._plan-styles')
@endpush
@section('content')
@php($base = app()->getLocale() === 'fa' ? route('marketing.pricing') : route('marketing.pricing.en'))
<section><div class="container">
<h1 style="font-size:36px;margin:0 0 8px">{{ __('marketing.pricing_title') }}</h1>
<p class="lead">{{ __('marketing.pricing_text') }}</p>
<div class="toggle" style="margin-top:12px"><a href="{{ $base }}" class="{{ $interval === 'monthly' ? 'on' : '' }}">{{ __('billing.monthly') }}</a><a href="{{ $base }}?interval=yearly" class="{{ $interval === 'yearly' ? 'on' : '' }}">{{ __('billing.yearly') }}</a></div>
@include('billing._plans', ['actions' => false])
<p class="muted" style="margin-top:18px">{{ __('billing.switch_note') }}</p>
<p style="margin-top:16px">@if(\App\Http\Controllers\RegisterController::enabled())<a class="btn primary" href="{{ route('register') }}">{{ __('marketing.cta_start') }}</a>@endif</p>
</div></section>
@endsection
