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
/* hero */
.hero{padding:72px 0 88px;text-align:center;background:linear-gradient(180deg,#f3f4ff 0,#fff 70%)}
.badge{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;background:#fff;border:1px solid var(--accent-line);color:var(--accent-600);font-size:12.5px;font-weight:700;direction:ltr}
.badge svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2}
.hero h1{font-size:50px;line-height:1.35;margin:18px auto 16px;letter-spacing:-.02em;max-width:760px;font-weight:900}
.hero p.lede{font-size:18px;color:var(--muted);max-width:640px;margin:0 auto 28px}
.cta-row{display:flex;gap:12px;flex-wrap:wrap;justify-content:center}.hero .note{font-size:13.5px;color:var(--muted);margin-top:14px}
/* product mock */
.mock{position:relative;max-width:720px;margin:56px auto 0;text-align:start}
.mock .win{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 30px 70px rgba(35,40,110,.12)}
.mock .top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px}
.mock .top small{display:block;color:var(--muted);font-size:12px}.mock .top b{font-size:16px}
.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;background:var(--green-soft);color:var(--green)}
.mstats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px}
.mstats div{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:#fcfcff}
.mstats small{display:block;color:var(--muted);font-size:12px}.mstats b{font-size:22px;font-weight:800}
.mtask{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;background:var(--bg);margin-top:8px;font-size:14px}
.mtask time{color:var(--accent-600);font-weight:700;font-size:12.5px;direction:ltr;min-width:44px}
.mtask span{flex:1}
.prio{font-size:11.5px;font-weight:800;padding:2px 8px;border-radius:6px;background:var(--coral-soft);color:#d7613f}.prio.p2{background:var(--amber-soft);color:#b7810f}
.maisug{display:flex;gap:10px;align-items:center;margin-top:12px;padding:12px 14px;border-radius:12px;background:var(--accent-soft);color:#393d8f;font-size:13.5px}
.maisug svg{width:18px;height:18px;flex:none;stroke:var(--accent);fill:none;stroke-width:1.8}
.float{position:absolute;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;box-shadow:0 10px 24px rgba(35,40,110,.18)}
.float svg{width:22px;height:22px;stroke:#fff;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.float.f1{background:var(--accent);top:52px;inset-inline-start:-22px}.float.f2{background:var(--green);bottom:48px;inset-inline-end:-22px}
/* why */
.why .card{border-top:3px solid var(--accent);box-shadow:0 8px 24px rgba(35,40,110,.05)}
.ico{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent)}
.ico svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.ico.c1{background:var(--accent-soft);color:var(--accent)}.ico.c2{background:var(--green-soft);color:var(--green)}.ico.c3{background:var(--amber-soft);color:#c9901c}.ico.c4{background:var(--coral-soft);color:#e06a47}.ico.c5{background:var(--sky-soft);color:var(--sky)}
.benefits{display:flex;flex-wrap:wrap;justify-content:center;gap:4px 28px;margin-top:30px;color:var(--muted);font-size:14.5px}.benefits li{margin:4px 0}
/* how */
.how-art{display:block;width:min(420px,100%);margin:10px auto 28px}
.step-n{width:36px;height:36px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800;margin:0 auto 10px;box-shadow:0 6px 16px rgba(99,102,241,.3)}
.steps .item{text-align:center;padding:0 12px}.steps h3{margin:0 0 4px;font-size:17px}.steps p{margin:0;color:var(--muted)}
/* features */
.features .card{padding:20px;transition:transform .18s,box-shadow .18s}.features .card:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(35,40,110,.08)}
.features .card h3{font-size:15.5px;margin-top:12px}.features .card p{font-size:14px}
/* AI */
.kicker{display:inline-flex;align-items:center;gap:6px;color:var(--accent-600);font-weight:800;font-size:13.5px;margin-bottom:6px}
.kicker svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}
.art{width:min(460px,100%);display:block;margin-inline:auto}
/* telegram band */
.tg-band{background:linear-gradient(135deg,#6366f1,#5a5ee6);color:#fff;padding:80px 0}
.tg-band h2{color:#fff}.tg-band p{color:#e4e6ff;font-size:16.5px}
.tg-head{display:flex;align-items:center;gap:12px}.tg-head .ico{background:rgba(255,255,255,.16);color:#fff}
.tg-points{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:22px}
.tg-points div{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:12px;padding:12px 14px;font-size:14px;display:flex;gap:10px;align-items:flex-start}
.tg-points svg{width:18px;height:18px;flex:none;stroke:#b9f2d9;fill:none;stroke-width:2.4;margin-top:3px}
/* pricing */
.pgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:34px;align-items:stretch}
.pcard{background:#fff;border:1px solid var(--line);border-radius:18px;padding:26px;display:flex;flex-direction:column;gap:6px;box-shadow:0 8px 24px rgba(35,40,110,.05)}
.pcard.featured{border:2px solid var(--accent);box-shadow:0 18px 44px rgba(99,102,241,.18)}
.pcard .pname{display:flex;justify-content:space-between;align-items:center;font-weight:800;font-size:19px}
.pcard .pname small{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:99px;background:var(--accent-soft);color:var(--accent-600)}
.pcard .pdesc{color:var(--muted);font-size:14px;min-height:46px}
.pcard .price{font-size:30px;font-weight:900;margin-top:6px}.pcard .price small{display:block;font-size:13px;color:var(--muted);font-weight:600}
.pcard .ptrial{display:inline-block;margin-top:8px;font-size:12.5px;font-weight:700;color:var(--accent);background:color-mix(in srgb,var(--accent) 10%,transparent);padding:4px 10px;border-radius:999px}
.pcard .checks{font-size:14px;margin:12px 0 18px}.pcard .checks li{margin:7px 0}
.pcard .btn{margin-top:auto;width:100%}.pcard:not(.featured) .btn{background:#fff;color:var(--ink);border-color:var(--line);box-shadow:none}
/* faq */
.faq-list{max-width:780px;margin:30px auto 0;border-top:1px solid var(--line)}
.faq-list details{border-bottom:1px solid var(--line)}
.faq-list summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 4px;font-weight:700}
.faq-list summary::-webkit-details-marker{display:none}
.faq-list summary svg{width:18px;height:18px;flex:none;stroke:var(--muted);fill:none;stroke-width:2;transition:transform .2s}
.faq-list details[open] summary svg{transform:rotate(180deg)}
.faq-list p{margin:0;padding:0 4px 18px;color:var(--muted)}
/* final cta */
.final{text-align:center;padding-bottom:96px}.final img{width:min(300px,80%);display:block;margin:0 auto 8px}
.final h2{font-size:34px}.final p{color:var(--muted);margin:0 0 22px}
@media(max-width:960px){.hero h1{font-size:34px}.hero{padding:48px 0 64px}.mstats b{font-size:18px}.float{display:none}.pgrid{grid-template-columns:1fr}.tg-points{grid-template-columns:1fr}.grid2 .art{order:-1;width:min(320px,90%)}}
@media(max-width:560px){.mstats{grid-template-columns:1fr 1fr 1fr;gap:6px}.mstats div{padding:8px}.mock .win{padding:14px}}
@endpush
@php
    $I = [
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'goal' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>',
        'flag' => '<path d="M5 21V4"/><path d="M5 4.5h11l-2 4 2 4H5"/>',
        'phone' => '<rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
        'tasks' => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="m8 12 3 3 5-6"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'timer' => '<circle cx="12" cy="13.5" r="7.5"/><path d="M12 9.5v4l2.5 2M9.5 2.5h5"/>',
        'chart' => '<path d="M4 20.5h16"/><rect x="5.5" y="11" width="3" height="7" rx=".8"/><rect x="10.5" y="6" width="3" height="12" rx=".8"/><rect x="15.5" y="13.5" width="3" height="4.5" rx=".8"/>',
        'note' => '<path d="M5 3.5h14A1.5 1.5 0 0 1 20.5 5v9.5l-6 6H5A1.5 1.5 0 0 1 3.5 19V5A1.5 1.5 0 0 1 5 3.5z"/><path d="M14.5 20.5v-6h6M8 8.5h8M8 12h5"/>',
        'bell' => '<path d="M6 16.5V11a6 6 0 1 1 12 0v5.5l1.5 2h-15z"/><path d="M10 21h4"/>',
        'haman' => '<circle cx="12" cy="12" r="8.5"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
        'bot' => '<rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M8.5 16h7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chev' => '<path d="m6 9 6 6 6-6"/>',
        'layers' => '<path d="m12 3 9 4.5-9 4.5-9-4.5z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
    ];
    $svg = fn ($k) => '<svg viewBox="0 0 24 24" aria-hidden="true">'.$I[$k].'</svg>';
    $featureIcons = [['goal', 'c1'], ['tasks', 'c2'], ['calendar', 'c3'], ['link', 'c4'], ['timer', 'c5'], ['chart', 'c2'], ['note', 'c3'], ['bell', 'c4']];
    $canRegister = \App\Http\Controllers\RegisterController::enabled();
    $start = $canRegister ? route('register') : route('login');
    $publicPlans = $plans->values();
    $featuredIndex = $publicPlans->count() >= 3 ? 1 : $publicPlans->search(fn ($p) => !$p->isFree());
@endphp
@section('content')
<section class="hero"><div class="container">
<span class="badge">{!! $svg('layers') !!}{{ __('marketing.hero_badge') }}</span>
<h1>{{ __('marketing.hero_title') }}</h1>
<p class="lede">{{ __('marketing.hero_text') }}</p>
<div class="cta-row">
@if($canRegister)<a class="btn primary lg" href="{{ route('register') }}">{!! $svg('plus') !!}{{ __('marketing.cta_start') }}</a>@endif
<a class="btn ghost lg" href="{{ route('login') }}">{{ __('marketing.login') }}</a>
</div>
<div class="note">{{ __('marketing.hero_note') }}</div>

<figure class="mock" aria-label="{{ __('marketing.preview_caption') }}" style="margin-bottom:0">
<div class="win">
<div class="top"><div><small>{{ __('marketing.preview_title') }}</small><b>{{ __('marketing.preview_today') }}</b></div><span class="chip">{{ __('marketing.preview_metrics')[1][1] ?? '' }}</span></div>
<div class="mstats">@foreach (__('marketing.preview_metrics') as [$k, $v])<div><small>{{ $k }}</small><b>{{ $v }}</b></div>@endforeach</div>
@foreach (__('marketing.preview_tasks') as [$time, $title, $prio])<div class="mtask"><time>{{ $time }}</time><span>{{ $title }}</span><b class="prio {{ strtolower($prio) }}">{{ $prio }}</b></div>@endforeach
<div class="maisug">{!! $svg('haman') !!}<span>{{ __('marketing.preview_ai') }}</span></div>
</div>
<span class="float f1" aria-hidden="true">{!! $svg('haman') !!}</span>
<span class="float f2" aria-hidden="true">{!! $svg('calendar') !!}</span>
<figcaption class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden">{{ __('marketing.preview_caption') }}</figcaption>
</figure>
</div></section>

<section class="why"><div class="container">
<h2 class="center">{{ __('marketing.value_title') }}</h2>
<div class="grid3" style="margin-top:34px">
@foreach ([1 => ['goal', 'c1'], 2 => ['flag', 'c2'], 3 => ['phone', 'c3']] as $i => [$icon, $c])
<div class="card"><div class="ico {{ $c }}">{!! $svg($icon) !!}</div><h3>{{ __('marketing.value_'.$i.'_title') }}</h3><p>{{ __('marketing.value_'.$i.'_text') }}</p></div>
@endforeach
</div>
@if(count((array) __('marketing.benefits')))<ul class="checks benefits" aria-label="{{ __('marketing.benefits_title') }}">@foreach (__('marketing.benefits') as $b)<li>{{ $b }}</li>@endforeach</ul>@endif
</div></section>

<section id="how" class="alt"><div class="container">
<h2 class="center">{{ __('marketing.how_title') }}</h2>
<img class="how-art" src="{{ asset('images/landing/how-it-works.webp') }}" width="720" height="720" loading="lazy" alt="">
<div class="grid3 steps">
@foreach ([1, 2, 3] as $i)
<div class="item"><div class="step-n">{{ \App\Support\LocalDate::number($i) }}</div><h3>{{ __('marketing.how_'.$i.'_title') }}</h3><p>{{ __('marketing.how_'.$i.'_text') }}</p></div>
@endforeach
</div>
</div></section>

<section id="features" class="features"><div class="container">
<h2 class="center">{{ __('marketing.features_title') }}</h2>
<div class="grid4" style="margin-top:34px">
@foreach (__('marketing.features') as $n => [$glyph, $title, $text])
@php([$icon, $c] = $featureIcons[$n % count($featureIcons)])
<div class="card"><div class="ico {{ $c }}">{!! $svg($icon) !!}</div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
@endforeach
</div>
</div></section>

<section class="alt"><div class="container grid2">
<div>
<div class="kicker">{!! $svg('haman') !!}Haman AI</div>
<h2>{{ __('marketing.ai_title') }}</h2>
<p class="lead">{{ __('marketing.ai_text') }}</p>
<ul class="checks">@foreach (__('marketing.ai_points') as $p)<li>{{ $p }}</li>@endforeach</ul>
</div>
<img class="art" src="{{ asset('images/landing/ai-assistant.webp') }}" width="720" height="720" loading="lazy" alt="">
</div></section>

<section class="tg-band"><div class="container grid2">
<div>
<div class="tg-head"><span class="ico">{!! $svg('bot') !!}</span><h2 style="margin:0">{{ __('marketing.telegram_title') }}</h2></div>
<p style="margin-top:14px">{{ __('marketing.telegram_text') }}</p>
<div class="tg-points">@foreach (__('marketing.telegram_points') as $p)<div>{!! $svg('check') !!}<span>{{ $p }}</span></div>@endforeach</div>
</div>
<img class="art" src="{{ asset('images/landing/telegram.webp') }}" width="720" height="720" loading="lazy" alt="" style="width:min(380px,100%)">
</div></section>

<section id="pricing"><div class="container center">
<h2>{{ __('marketing.pricing_title') }}</h2>
<p class="lead">{{ __('marketing.pricing_text') }}</p>
<div class="pgrid" style="text-align:start">
@foreach ($publicPlans as $n => $p)
@php($price = $p->price($currency, 'monthly'))
<div class="pcard {{ $n === $featuredIndex ? 'featured' : '' }}">
<div class="pname">{{ $p->localizedName() }}@if($n === $featuredIndex)<small>{{ __('marketing.plan_popular') }}</small>@endif</div>
<div class="pdesc">{{ $p->localizedDescription() }}</div>
<div class="price">@if($p->isFree()){{ __('billing.free') }}@elseif($price === null)<span style="font-size:15px;color:var(--muted)">{{ __('billing.not_sold') }}</span>@else{{ \App\Support\Money::format($price, $currency) }}<small>{{ __('billing.per_interval.monthly') }}</small>@endif</div>
@if(!$p->isFree() && $p->trial_days > 0)<div class="ptrial">{{ __('marketing.plan_trial', ['days' => \App\Support\LocalDate::number($p->trial_days)]) }}</div>@endif
<ul class="checks">
@foreach (array_keys((array) config('billing.metrics')) as $m)
@php($lim = $p->limit($m))
@if ($lim !== null || $m === 'ai_requests' || $publicPlans->contains(fn ($o) => $o->limit($m) !== null))<li>{{ __('billing.metric.'.$m) }}: <b>{{ $lim === null ? __('common.unlimited') : \App\Support\LocalDate::number($lim) }}</b></li>@endif
@endforeach
@foreach ((array) config('billing.features') as $f)@if($p->hasFeature($f))<li>{{ __('billing.feature.'.$f) }}</li>@endif @endforeach
</ul>
<a class="btn primary" href="{{ $start }}">{{ $canRegister ? __('marketing.cta_start') : __('marketing.login') }}</a>
</div>
@endforeach
</div>
<p style="margin-top:22px"><a class="btn ghost sm" href="{{ app()->getLocale() === 'fa' ? route('marketing.pricing') : route('marketing.pricing.en') }}">{{ __('marketing.plan_compare') }}</a></p>
</div></section>

<section id="faq" class="alt"><div class="container">
<h2 class="center">{{ __('marketing.faq_title') }}</h2>
<div class="faq-list">
@foreach (__('marketing.faq') as [$q, $a])
<details><summary>{{ $q }}{!! $svg('chev') !!}</summary><p>{{ $a }}</p></details>
@endforeach
</div>
</div></section>

<section class="final"><div class="container">
<img src="{{ asset('images/landing/cta-planner.webp') }}" width="720" height="720" loading="lazy" alt="">
<h2>{{ __('marketing.cta_title') }}</h2>
<p>{{ __('marketing.cta_text') }}</p>
<a class="btn primary lg" href="{{ $start }}">{!! $svg('plus') !!}{{ $canRegister ? __('marketing.cta_start') : __('marketing.login') }}</a>
</div></section>
@endsection
