@php($brand = \App\Support\AppSettings::all())
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locales::dir() }}">
<head>
@include('partials.fonts')
@include('partials.pwa')
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title') | {{ $brand['app_name'] }}</title>
<link rel="stylesheet" href="{{ asset('css/haman.css') }}?v={{ @filemtime(public_path('css/haman.css')) }}">
<style>
.top{background:var(--surface);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:30}
.top .in{max-width:1180px;margin:auto;padding:10px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.top .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px;color:var(--ink-900);text-decoration:none}
.top .brand img{height:28px;max-width:120px;object-fit:contain}
.top .brand .mark{width:28px;height:28px;border-radius:8px;background:var(--ink-900);color:#fff;display:grid;place-items:center;font-size:14px}
.top nav{display:flex;gap:2px;flex-wrap:wrap;margin-inline-start:auto;align-items:center}
.top nav a,.top nav button{color:var(--text-2);padding:7px 11px;border-radius:var(--r);background:none;border:0;font-weight:var(--w-medium);text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.top nav a:hover,.top nav button:hover{background:var(--surface-3);color:var(--text);text-decoration:none}
.top nav a.on{background:var(--accent-soft);color:var(--accent-strong)}
.top .lang-switch{color:var(--text-3);font-size:var(--fs-xs);padding-inline:8px}.lang-switch .sep{margin-inline:6px}.lang-switch strong{color:var(--text)}
.wrap{max-width:1180px;margin:24px auto 48px;padding:0 20px}
.wrap > h1{margin-bottom:var(--s4)}
.wrap > .card{margin-bottom:var(--s4)}
.sub{display:flex;gap:2px;flex-wrap:wrap;margin:-8px 0 20px;border-bottom:1px solid var(--line);overflow-x:auto}
.sub a{padding:9px 12px;color:var(--text-2);font-weight:var(--w-medium);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;text-decoration:none}
.sub a:hover{color:var(--text);text-decoration:none}
.sub a.on{color:var(--accent-strong);border-bottom-color:var(--accent)}
.msg{border:1px solid var(--line);border-radius:var(--r-lg);padding:12px 14px;margin:10px 0;background:var(--surface);white-space:pre-wrap;line-height:1.9}.msg.staff{background:var(--info-bg);border-color:var(--info-line)}.msg .meta{font-size:var(--fs-sm);color:var(--text-3);margin-bottom:6px;white-space:normal}
@media(max-width:760px){.top nav{margin-inline-start:0;width:100%;overflow-x:auto;flex-wrap:nowrap}.wrap{padding:0 16px;margin-top:16px}}
@stack('styles')
</style>
</head>
<body>
@include('partials.icons')
<a class="skip-link" href="#main">{{ __('app.home.skip') }}</a>
<header class="top"><div class="in">
<a class="brand" href="{{ route('planner.app') }}">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@else<span class="mark" aria-hidden="true">{{ mb_substr($brand['app_name'], 0, 1) }}</span>@endif{{ $brand['app_name'] }}</a>
<nav>
<a href="{{ route('planner.app') }}">{{ __('app.nav.planner') }}</a>
<a href="{{ route('billing.index') }}" class="{{ request()->routeIs('billing.*') ? 'on' : '' }}">{{ __('app.nav.billing') }}</a>
@if($brand['support_enabled'] || auth()->user()->is_admin)<a href="{{ route('support.index') }}" class="{{ request()->routeIs('support.*') ? 'on' : '' }}">{{ __('app.nav.support') }}</a>@endif
<a href="{{ route('account.settings') }}" class="{{ request()->routeIs('account.*') ? 'on' : '' }}">{{ __('app.nav.settings') }}</a>
@if(auth()->user()->is_admin)<a href="{{ route('admin.home') }}" class="{{ request()->routeIs('admin.*') ? 'on' : '' }}">{{ __('app.nav.admin') }}</a>@endif
<form method="post" action="{{ route('logout') }}" style="display:inline">@csrf<button>{{ __('common.logout') }}</button></form>
@include('partials.lang-switch')
</nav>
</div></header>
<main class="wrap" id="main">
@if(request()->routeIs('admin.*'))
<div class="sub">
<a href="{{ route('admin.home') }}" class="{{ request()->routeIs('admin.home') ? 'on' : '' }}">{{ __('admin.nav.dashboard') }}</a>
<a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users*') ? 'on' : '' }}">{{ __('admin.nav.users') }}</a>
<a href="{{ route('admin.subscriptions') }}" class="{{ request()->routeIs('admin.subscriptions*') ? 'on' : '' }}">{{ __('admin.nav.subscriptions') }}</a>
<a href="{{ route('admin.plans') }}" class="{{ request()->routeIs('admin.plans*') ? 'on' : '' }}">{{ __('admin.nav.plans') }}</a>
<a href="{{ route('admin.analytics') }}" class="{{ request()->routeIs('admin.analytics*') ? 'on' : '' }}">{{ __('admin.nav.analytics') }}</a>
<a href="{{ route('admin.payments') }}" class="{{ request()->routeIs('admin.payments*') ? 'on' : '' }}">{{ __('admin.nav.payments') }}</a>
<a href="{{ route('admin.payment-settings') }}" class="{{ request()->routeIs('admin.payment-settings*') ? 'on' : '' }}">{{ __('admin.nav.payment_settings') }}</a>
<a href="{{ route('admin.content') }}" class="{{ request()->routeIs('admin.content*') ? 'on' : '' }}">{{ __('admin.nav.content') }}</a>
<a href="{{ route('admin.integrations') }}" class="{{ request()->routeIs('admin.integrations*') ? 'on' : '' }}">{{ __('admin.nav.integrations') }}</a>
<a href="{{ route('admin.support') }}" class="{{ request()->routeIs('admin.support*') ? 'on' : '' }}">{{ __('admin.nav.tickets') }}</a>
<a href="{{ route('admin.system') }}" class="{{ request()->routeIs('admin.system*') ? 'on' : '' }}">{{ __('admin.nav.system') }}</a>
<a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings*') ? 'on' : '' }}">{{ __('admin.nav.settings') }}</a>
<a href="{{ route('admin.access') }}" class="{{ request()->routeIs('admin.access') ? 'on' : '' }}">{{ __('admin.nav.access') }}</a>
</div>
@endif
@if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
@if($errors->any())<div class="err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
@yield('content')
</main>
@stack('scripts')
</body></html>
