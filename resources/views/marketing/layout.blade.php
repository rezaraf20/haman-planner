@php
    $brand = \App\Support\AppSettings::all();
    $loc = app()->getLocale();
    $name = \Illuminate\Support\Facades\Route::currentRouteName();
    $faUrl = \App\Support\LocaleUrls::counterpart($name, 'fa');
    $enUrl = \App\Support\LocaleUrls::counterpart($name, 'en');
    $canonical = $loc === 'fa' ? $faUrl : $enUrl;
    $metaTitle = trim($__env->yieldContent('meta_title')) ?: __('marketing.meta_title');
    $metaDesc = trim($__env->yieldContent('meta_description')) ?: __('marketing.meta_description');
    $home = $loc === 'fa' ? route('marketing.home') : route('marketing.home.en');
    $pricing = $loc === 'fa' ? route('marketing.pricing') : route('marketing.pricing.en');
@endphp
<!doctype html>
<html lang="{{ $loc }}" dir="{{ \App\Support\Locales::dir() }}">
<head>
@include('partials.fonts')
@include('partials.pwa')
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $metaTitle }}</title>
<meta name="description" content="{{ $metaDesc }}">
<meta name="robots" content="index,follow">
<link rel="canonical" href="{{ $canonical }}">
<link rel="alternate" hreflang="fa" href="{{ $faUrl }}">
<link rel="alternate" hreflang="en" href="{{ $enUrl }}">
<link rel="alternate" hreflang="x-default" href="{{ $enUrl }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $brand['app_name'] }}">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDesc }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:locale" content="{{ $loc === 'fa' ? 'fa_IR' : 'en_US' }}">
<meta property="og:locale:alternate" content="{{ $loc === 'fa' ? 'en_US' : 'fa_IR' }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $metaTitle }}">
<meta name="twitter:description" content="{{ $metaDesc }}">
@stack('jsonld')
<style>
:root{--ink:#16193a;--muted:#5d6380;--line:#e8e9f3;--bg:#f7f8fc;--dark:#16193a;--accent:#6366f1;--accent-600:#5458e6;--accent-soft:#eef0ff;--accent-line:#dcdffd;--green:#22b07d;--green-soft:#e6f7f0;--amber:#f5b83d;--amber-soft:#fff6e3;--coral:#f58a6b;--coral-soft:#fff0eb;--sky:#4b8ef1;--sky-soft:#eaf2ff;--r:14px}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:var(--font);color:var(--ink);background:#fff;line-height:1.8;-webkit-font-smoothing:antialiased}
a{color:inherit}img{max-width:100%;height:auto}.container{width:min(1140px,calc(100% - 32px));margin-inline:auto}
header.site{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.9);backdrop-filter:saturate(1.4) blur(10px);border-bottom:1px solid var(--line)}
header.site .in{display:flex;align-items:center;gap:20px;height:68px}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px;text-decoration:none;color:var(--ink)}.logo img{height:30px}
.logo .mark{width:30px;height:30px;border-radius:9px;background:var(--accent);color:#fff;display:grid;place-items:center}
.logo .mark svg{width:17px;height:17px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
header.site .menu{display:flex;gap:26px;margin-inline:auto;font-size:14.5px}header.site .menu a{text-decoration:none;color:var(--muted);font-weight:500}header.site .menu a:hover{color:var(--ink)}
header.site .end{display:flex;align-items:center;gap:10px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:11px;padding:11px 20px;font-weight:700;text-decoration:none;border:1px solid transparent;font-size:15px;line-height:1.2;transition:background .15s,border-color .15s,transform .15s,box-shadow .15s}
.btn.primary{background:var(--accent);color:#fff;box-shadow:0 6px 18px rgba(99,102,241,.28)}.btn.primary:hover{background:var(--accent-600);transform:translateY(-1px)}
.btn.ghost{background:#fff;color:var(--ink);border-color:var(--line)}.btn.ghost:hover{border-color:#cfd2e6}
.btn.sm{padding:8px 14px;font-size:13.5px;border-radius:9px}.btn.lg{padding:13px 24px;font-size:16px}
.btn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.lang-switch{font-size:13px;color:var(--muted);white-space:nowrap}.lang-switch .sep{display:none}.lang-switch strong{display:none}.lang-switch a{text-decoration:none;font-weight:600}
section{padding:88px 0}section.alt{background:var(--bg)}
h2{font-size:32px;margin:0 0 12px;line-height:1.4;font-weight:800;letter-spacing:-.01em}.lead{color:var(--muted);font-size:17px;max-width:680px}
.center{text-align:center}.center .lead{margin-inline:auto}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center}
.card{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:24px}.card h3{margin:8px 0 6px;font-size:17px}.card p{margin:0;color:var(--muted)}
.checks{list-style:none;padding:0;margin:16px 0 0}.checks li{padding-inline-start:30px;position:relative;margin:10px 0}.checks li::before{content:"";position:absolute;inset-inline-start:0;top:.45em;width:18px;height:18px;border-radius:50%;background:var(--green-soft) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2322b07d' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 12.5 4 4 8-9'/%3E%3C/svg%3E") center/12px no-repeat}
footer.site{border-top:1px solid var(--line);padding:28px 0;font-size:13.5px;color:var(--muted)}footer.site .in{display:flex;gap:20px;flex-wrap:wrap;align-items:center;justify-content:space-between}
footer.site a{text-decoration:none}footer.site a:hover{color:var(--ink)}footer.site nav{display:flex;gap:18px;flex-wrap:wrap;align-items:center}
:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.35);border-radius:8px}
@media(max-width:960px){.grid3,.grid4,.grid2{grid-template-columns:1fr}.grid4{grid-template-columns:1fr 1fr}header.site .menu{display:none}section{padding:60px 0}h2{font-size:26px}}
@media(max-width:560px){.grid4{grid-template-columns:1fr}header.site .end .btn.ghost{display:none}}
@media(prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important;scroll-behavior:auto!important}}
@stack('styles')
</style>
</head>
<body>
<header class="site"><div class="container in">
<a class="logo" href="{{ $home }}">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@else<span class="mark" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg></span>@endif{{ $brand['app_name'] }}</a>
<nav class="menu" aria-label="{{ __('marketing.nav_features') }}">
<a href="{{ $home }}#features">{{ __('marketing.nav_features') }}</a>
<a href="{{ $home }}#how">{{ __('marketing.nav_how') }}</a>
<a href="{{ $home }}#pricing">{{ __('marketing.nav_pricing') }}</a>
<a href="{{ $home }}#faq">{{ __('marketing.nav_faq') }}</a>
</nav>
<div class="end">
@include('partials.lang-switch')
@auth<a class="btn sm primary" href="{{ route('planner.app') }}">{{ __('marketing.cta_open') }}</a>@else<a class="btn sm ghost" href="{{ route('login') }}">{{ __('marketing.login') }}</a>@if(\App\Http\Controllers\RegisterController::enabled())<a class="btn sm primary" href="{{ route('register') }}">{{ __('marketing.cta_start') }}</a>@endif @endauth
</div>
</div></header>
<main>@yield('content')</main>
<footer class="site"><div class="container in">
<a class="logo" href="{{ $home }}" style="font-size:15px">@unless($brand['logo'])<span class="mark" aria-hidden="true" style="width:26px;height:26px"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg></span>@endunless{{ $brand['app_name'] }}</a>
<div>{{ __('marketing.footer_rights', ['year' => now()->year]) }}</div>
<nav>
<a href="{{ $pricing }}">{{ __('marketing.footer_pricing') }}</a>
<a href="{{ \App\Support\LocaleUrls::counterpart('marketing.privacy', $loc) }}">{{ __('marketing.footer_privacy') }}</a>
<a href="{{ \App\Support\LocaleUrls::counterpart('marketing.terms', $loc) }}">{{ __('marketing.footer_terms') }}</a>
@if(config('services.haman_planner.contact_email'))<a href="mailto:{{ config('services.haman_planner.contact_email') }}">{{ __('marketing.footer_contact') }}</a>@endif
@include('partials.lang-switch')
</nav>
</div></footer>
</body></html>
