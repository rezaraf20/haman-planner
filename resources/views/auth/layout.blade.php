@php($brand = \App\Support\AppSettings::all())
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locales::dir() }}">
<head>
@include('partials.fonts')
@include('partials.pwa')
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') | {{ $brand['app_name'] }}</title>
<link rel="stylesheet" href="{{ asset('css/haman.css') }}?v={{ @filemtime(public_path('css/haman.css')) }}">
<style>
body{min-height:100vh;display:grid;place-items:center;padding:32px 16px;background:var(--bg)}
.auth{width:min(420px,100%)}
.auth .card{padding:32px;box-shadow:var(--shadow-1)}
.auth .head{align-items:flex-start;margin-bottom:0}
.logo{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700;color:var(--ink-900)}
.logo .mark{width:32px;height:32px;border-radius:9px;background:var(--ink-900);color:#fff;display:grid;place-items:center;font-size:15px}
.sub{color:var(--text-3);margin:10px 0 22px;line-height:1.8}
.auth .field{margin:0 0 14px}
.auth .btn{width:100%;min-height:42px}
.auth .btn:not(.ghost){background:var(--ink-900);border-color:var(--ink-900);color:#fff}
.auth .btn:not(.ghost):hover{background:var(--ink-700)}
.row{justify-content:space-between;margin:4px 0 18px;font-size:var(--fs-sm)}
.remember{display:flex;gap:8px;align-items:center;color:var(--text-2)}
.error{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-line);padding:10px 12px;border-radius:var(--r-md);font-size:var(--fs-sm);line-height:1.8;margin-bottom:12px}
.foot{text-align:center;font-size:var(--fs-sm);color:var(--text-3);margin-top:18px;line-height:1.9}
.legal{font-size:var(--fs-xs);color:var(--text-3);margin-top:10px;line-height:1.8}
.hp{position:absolute;inset-inline-start:-10000px;width:1px;height:1px;overflow:hidden}
.lang-switch{font-size:var(--fs-xs);color:var(--text-3);white-space:nowrap}.lang-switch .sep{margin-inline:6px}.lang-switch strong{color:var(--text)}
.auth-note{text-align:center;color:var(--text-3);font-size:var(--fs-xs);margin-top:16px}
</style>
</head>
<body><div class="auth"><main class="card">
<div class="head">
<div>@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="" style="max-height:48px;max-width:180px;object-fit:contain;display:block;margin-bottom:10px">@endif
<div class="logo">@unless($brand['logo'])<span class="mark" aria-hidden="true">{{ mb_substr($brand['app_name'], 0, 1) }}</span>@endunless{{ $brand['app_name'] }}</div></div>
@include('partials.lang-switch')
</div>
<div class="sub">@hasSection('subtitle')@yield('subtitle')@else{{ $brand['tagline'] }}@endif</div>
@if (session('status'))<div class="ok" role="status">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="error" role="alert">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
@yield('content')
</main></div></body></html>
