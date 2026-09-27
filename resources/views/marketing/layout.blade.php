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
:root{--ink:#111827;--muted:#5b6475;--line:#e6e9ef;--bg:#f6f7fb;--dark:#0f1726;--accent:#3157d5;--accent2:#7c3aed}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Vazirmatn,Inter,system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;color:var(--ink);background:#fff;line-height:1.75}
a{color:inherit}.container{width:min(1120px,calc(100% - 32px));margin-inline:auto}
header.site{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
header.site .in{display:flex;align-items:center;gap:18px;height:64px}.logo{display:flex;align-items:center;gap:8px;font-weight:900;font-size:18px;text-decoration:none}.logo img{height:30px}
header.site nav{display:flex;gap:18px;margin-inline-start:auto;align-items:center;font-size:14px}header.site nav a{text-decoration:none;color:var(--muted)}header.site nav a:hover{color:var(--ink)}
.btn{display:inline-block;border-radius:12px;padding:11px 18px;font-weight:800;text-decoration:none;border:1px solid var(--ink);font-size:15px}.btn.primary{background:var(--ink);color:#fff}.btn.ghost{background:#fff;color:var(--ink)}.btn.sm{padding:7px 12px;font-size:13px}
.lang-switch{font-size:13px;color:var(--muted);white-space:nowrap}.lang-switch .sep{margin-inline:6px}.lang-switch a{text-decoration:none}
section{padding:72px 0}section.alt{background:var(--bg)}h2{font-size:30px;margin:0 0 12px;line-height:1.35}.lead{color:var(--muted);font-size:17px;max-width:680px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:center}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px}.card h3{margin:6px 0 6px;font-size:17px}.card p{margin:0;color:var(--muted)}.icon{font-size:22px}
.checks{list-style:none;padding:0;margin:14px 0 0}.checks li{padding-inline-start:26px;position:relative;margin:8px 0}.checks li::before{content:"✓";position:absolute;inset-inline-start:0;color:var(--accent);font-weight:900}
footer.site{background:var(--dark);color:#cbd5e1;padding:36px 0;font-size:14px}footer.site .in{display:flex;gap:20px;flex-wrap:wrap;align-items:center;justify-content:space-between}footer.site a{color:#cbd5e1;text-decoration:none}footer.site nav{display:flex;gap:16px;flex-wrap:wrap}footer.site .lang-switch{color:#94a3b8}footer.site .lang-switch strong{color:#fff}
@media(max-width:900px){.grid3,.grid4,.grid2{grid-template-columns:1fr}header.site nav .hide-sm{display:none}section{padding:52px 0}h2{font-size:25px}}
@stack('styles')
</style>
</head>
<body>
<header class="site"><div class="container in">
<a class="logo" href="{{ $home }}">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@endif{{ $brand['app_name'] }}</a>
<nav>
<a class="hide-sm" href="{{ $home }}#features">{{ __('marketing.nav_features') }}</a>
<a class="hide-sm" href="{{ $home }}#how">{{ __('marketing.nav_how') }}</a>
<a class="hide-sm" href="{{ $pricing }}">{{ __('marketing.nav_pricing') }}</a>
<a class="hide-sm" href="{{ $home }}#faq">{{ __('marketing.nav_faq') }}</a>
@include('partials.lang-switch')
@auth<a class="btn sm primary" href="{{ route('planner.app') }}">{{ __('marketing.cta_open') }}</a>@else<a href="{{ route('login') }}">{{ __('marketing.login') }}</a>@if(\App\Http\Controllers\RegisterController::enabled())<a class="btn sm primary" href="{{ route('register') }}">{{ __('marketing.cta_start') }}</a>@endif @endauth
</nav>
</div></header>
<main>@yield('content')</main>
<footer class="site"><div class="container in">
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
