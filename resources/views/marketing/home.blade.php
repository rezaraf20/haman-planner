@extends('marketing.layout')
@push('jsonld')
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', 'name' => config('services.haman_planner.legal_name', 'HamanTech'), 'url' => url('/')],
        ['@type' => 'SoftwareApplication', 'name' => \App\Support\AppSettings::get('app_name'), 'applicationCategory' => 'ProductivityApplication', 'operatingSystem' => 'Web, Telegram',
            'inLanguage' => ['fa', 'en'], 'description' => __('marketing.meta_description'),
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => $currency === 'IRT' ? 'IRR' : $currency]],
        ['@type' => 'FAQPage', 'mainEntity' => collect(__('marketing.faq'))->map(fn ($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]])->values()->all()],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush
@push('styles')
.hero{padding:88px 0 64px;background:radial-gradient(900px 400px at 80% -10%,#e9edff 0,transparent 60%),radial-gradient(700px 360px at 0% 0%,#f3e8ff 0,transparent 55%)}
.hero h1{font-size:46px;line-height:1.25;margin:0 0 16px;letter-spacing:-.5px;max-width:820px}.hero p{font-size:19px;color:var(--muted);max-width:720px;margin:0 0 26px}.hero .note{font-size:14px;color:var(--muted);margin-top:12px}.cta-row{display:flex;gap:10px;flex-wrap:wrap}
.step{font-size:13px;font-weight:900;color:var(--accent)}
.preview{background:#0f1726;border-radius:22px;padding:18px;box-shadow:0 30px 80px rgba(15,23,38,.25);color:#e2e8f0}
.preview .bar{display:flex;gap:6px;margin-bottom:12px}.preview .bar i{width:10px;height:10px;border-radius:50%;background:#334155;display:block}
.preview .pane{background:#f4f6f9;border-radius:14px;padding:16px;color:#172033}.preview .metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:10px 0}
.preview .m{background:#fff;border:1px solid #e4e8ef;border-radius:10px;padding:8px}.preview .m small{display:block;color:#718096;font-size:11px}.preview .m b{font-size:18px}
.preview .t{display:flex;justify-content:space-between;background:#fff;border:1px solid #e4e8ef;border-radius:10px;padding:8px 10px;margin-top:6px;font-size:13px}.preview .ai{margin-top:10px;background:#eef2ff;border-radius:10px;padding:10px;font-size:13px}
.tg{background:linear-gradient(135deg,#229ED9,#1c7fb3);color:#fff;border-radius:22px;padding:28px}.tg .checks li::before{color:#fff}
.faq details{border:1px solid var(--line);border-radius:14px;padding:14px 18px;margin:10px 0;background:#fff}.faq summary{font-weight:800;cursor:pointer}.faq p{color:var(--muted);margin:10px 0 0}
.cta{background:var(--dark);color:#fff;border-radius:24px;padding:44px;text-align:center}.cta p{color:#cbd5e1}.cta .btn.primary{background:#fff;color:var(--ink);border-color:#fff}
@media(max-width:900px){.hero h1{font-size:32px}.hero{padding:56px 0 40px}}
@include('marketing._plan-styles')
@endpush
@section('content')
<section class="hero"><div class="container">
<h1>{{ __('marketing.hero_title') }}</h1>
<p>{{ __('marketing.hero_text') }}</p>
<div class="cta-row">
@if(\App\Http\Controllers\RegisterController::enabled())<a class="btn primary" href="{{ route('register') }}">{{ __('marketing.cta_start') }}</a>@endif
<a class="btn ghost" href="{{ route('login') }}">{{ __('marketing.login') }}</a>
</div>
<div class="note">{{ __('marketing.hero_note') }}</div>
</div></section>

<section class="alt"><div class="container">
<h2>{{ __('marketing.value_title') }}</h2>
<div class="grid3" style="margin-top:22px">
@foreach ([1, 2, 3] as $i)
<div class="card"><h3>{{ __('marketing.value_'.$i.'_title') }}</h3><p>{{ __('marketing.value_'.$i.'_text') }}</p></div>
@endforeach
</div>
</div></section>

<section id="how"><div class="container">
<h2>{{ __('marketing.how_title') }}</h2>
<div class="grid3" style="margin-top:22px">
@foreach ([1, 2, 3] as $i)
<div class="card"><div class="step">{{ \App\Support\LocalDate::number($i) }}</div><h3>{{ __('marketing.how_'.$i.'_title') }}</h3><p>{{ __('marketing.how_'.$i.'_text') }}</p></div>
@endforeach
</div>
</div></section>

<section id="features" class="alt"><div class="container">
<h2>{{ __('marketing.features_title') }}</h2>
<div class="grid4" style="margin-top:22px">
@foreach (__('marketing.features') as [$icon, $title, $text])
<div class="card"><div class="icon" aria-hidden="true">{{ $icon }}</div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
@endforeach
</div>
</div></section>

<section><div class="container grid2">
<div>
<h2>{{ __('marketing.ai_title') }}</h2>
<p class="lead">{{ __('marketing.ai_text') }}</p>
<ul class="checks">@foreach (__('marketing.ai_points') as $p)<li>{{ $p }}</li>@endforeach</ul>
</div>
<figure class="preview" aria-label="{{ __('marketing.preview_caption') }}" style="margin:0">
<div class="bar"><i></i><i></i><i></i></div>
<div class="pane">
<b>{{ __('marketing.preview_today') }}</b>
<div class="metrics">@foreach (__('marketing.preview_metrics') as [$k, $v])<div class="m"><small>{{ $k }}</small><b>{{ $v }}</b></div>@endforeach</div>
@foreach (__('marketing.preview_tasks') as [$time, $title, $prio])<div class="t"><span>{{ $time }} · {{ $title }}</span><b>{{ $prio }}</b></div>@endforeach
<div class="ai">✦ {{ __('marketing.preview_ai') }}</div>
</div>
<figcaption style="font-size:12px;color:#94a3b8;margin-top:8px">{{ __('marketing.preview_caption') }}</figcaption>
</figure>
</div></section>

<section class="alt"><div class="container">
<div class="tg grid2">
<div><h2>{{ __('marketing.telegram_title') }}</h2><p style="opacity:.9">{{ __('marketing.telegram_text') }}</p></div>
<ul class="checks">@foreach (__('marketing.telegram_points') as $p)<li>{{ $p }}</li>@endforeach</ul>
</div>
</div></section>

<section><div class="container">
<h2>{{ __('marketing.benefits_title') }}</h2>
<ul class="checks" style="font-size:17px">@foreach (__('marketing.benefits') as $b)<li>{{ $b }}</li>@endforeach</ul>
</div></section>

<section id="pricing" class="alt"><div class="container">
<h2>{{ __('marketing.pricing_title') }}</h2>
<p class="lead">{{ __('marketing.pricing_text') }}</p>
@include('billing._plans', ['actions' => false])
<p style="margin-top:16px"><a class="btn ghost" href="{{ app()->getLocale() === 'fa' ? route('marketing.pricing') : route('marketing.pricing.en') }}">{{ __('marketing.nav_pricing') }} →</a></p>
</div></section>

<section id="faq" class="faq"><div class="container">
<h2>{{ __('marketing.faq_title') }}</h2>
@foreach (__('marketing.faq') as [$q, $a])
<details><summary>{{ $q }}</summary><p>{{ $a }}</p></details>
@endforeach
</div></section>

<section><div class="container"><div class="cta">
<h2>{{ __('marketing.cta_title') }}</h2>
<p>{{ __('marketing.cta_text') }}</p>
@if(\App\Http\Controllers\RegisterController::enabled())<a class="btn primary" href="{{ route('register') }}">{{ __('marketing.cta_start') }}</a>@else<a class="btn primary" href="{{ route('login') }}">{{ __('marketing.login') }}</a>@endif
</div></div></section>
@endsection
