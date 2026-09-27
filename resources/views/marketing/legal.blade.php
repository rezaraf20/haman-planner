@extends('marketing.layout')
@section('meta_title', __('legal.'.$page.'_title').' | '.\App\Support\AppSettings::get('app_name'))
@section('meta_description', __('legal.'.$page.'_meta'))
@section('content')
<section><div class="container" style="max-width:820px">
<h1 style="font-size:34px;margin:0 0 6px">{{ __('legal.'.$page.'_title') }}</h1>
@unless (\App\Support\LandingContent::hideLegalNotice())
<p style="background:#fff7d6;border:1px solid #efd98a;border-radius:12px;padding:12px 14px;color:#6b4e00">{{ __('legal.draft_notice') }}</p>
@endunless
<p style="color:var(--muted)">{{ __('legal.operator', ['name' => config('services.haman_planner.legal_name', 'HamanTech'), 'email' => config('services.haman_planner.contact_email') ?: '—']) }}</p>
@foreach (__('legal.'.$page) as [$title, $text])
<h2 style="font-size:20px;margin-top:26px">{{ $title }}</h2>
<p>{{ $text }}</p>
@endforeach
</div></section>
@endsection
