@php($brand = \App\Support\AppSettings::all())
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locales::dir() }}">
<head>
@include('partials.fonts')
@include('partials.pwa')
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{{ __('pwa.offline_title') }} · {{ $brand['app_name'] }}</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6f9;color:#172033;font-family:var(--font);padding:20px}
.box{max-width:420px;text-align:center;background:#fff;border:1px solid #e4e8ef;border-radius:18px;padding:28px}
img{width:64px;height:64px}h1{font-size:22px;margin:14px 0 8px}p{color:#5b6475;line-height:1.9}button{border:0;border-radius:10px;padding:10px 16px;background:#172033;color:#fff;font:inherit;font-weight:700;cursor:pointer}</style>
</head>
<body><div class="box"><img src="{{ asset('icons/icon-192.png') }}" alt=""><h1>{{ __('pwa.offline_title') }}</h1><p>{{ __('pwa.offline_text') }}</p>
<p lang="{{ app()->getLocale() === 'fa' ? 'en' : 'fa' }}" dir="{{ app()->getLocale() === 'fa' ? 'ltr' : 'rtl' }}" style="font-size:13px">{{ __('pwa.offline_text', [], app()->getLocale() === 'fa' ? 'en' : 'fa') }}</p>
<button onclick="location.reload()">{{ __('pwa.retry') }}</button></div></body></html>
