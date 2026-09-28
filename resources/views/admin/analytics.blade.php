@extends('layouts.page')
@section('title', __('admin.an.title'))
@section('content')
@php($pc = app()->getLocale() === 'fa' ? '٪' : '%')
@php($n = fn ($v) => $v === null ? '—' : \App\Support\LocalDate::number($v))
@php($pct = fn ($r) => $r['rate'] === null ? '—' : \App\Support\LocalDate::number($r['rate']).$pc)
@php($a = $m['active'])
<h1>{{ __('admin.an.title') }}</h1>
<p class="help">{{ __('admin.an.intro') }}</p>

<div class="card">
<h2>{{ __('admin.an.active') }}</h2>
@if ($a['tracked_since'] === null)
<p class="muted">{{ __('admin.an.no_tracking') }}</p>
@else
<p class="help">{{ __('admin.an.tracked_since', ['date' => \App\Support\LocalDate::date($a['tracked_since'])]) }}</p>
<div class="grid">
<div class="stat"><span>DAU</span><b>{{ $n($a['dau']) }}</b></div>
<div class="stat"><span>WAU</span><b>{{ $n($a['wau']) }}</b>@if ($a['wau'] === null)<div class="help">{{ __('admin.an.partial', ['n' => $n($a['wau_partial'])]) }}</div>@endif</div>
<div class="stat"><span>MAU</span><b>{{ $n($a['mau']) }}</b>@if ($a['mau'] === null)<div class="help">{{ __('admin.an.partial', ['n' => $n($a['mau_partial'])]) }}</div>@endif</div>
<div class="stat"><span>{{ __('admin.an.stickiness') }}</span><b>{{ $a['stickiness'] === null ? '—' : \App\Support\LocalDate::number($a['stickiness']).$pc }}</b></div>
</div>
@php($max = max(1, ...array_map('intval', array_values($a['series']) ?: [0])))
<div class="bars" style="margin-top:12px">@foreach ($a['series'] as $day => $c)<div style="height:{{ $c === null ? 0 : max(3, (int) round($c / $max * 100)) }}%" title="{{ $day }}"><span>{{ $c === null ? '' : $n($c) }}</span></div>@endforeach</div>
@endif
<p class="help">{{ __('admin.an.active_def') }}</p>
</div>

<div class="card">
<h2>{{ __('admin.an.funnel') }}</h2>
<div class="tbl"><table>
<tr><th>{{ __('admin.an.metric') }}</th><th>{{ __('admin.an.value') }}</th><th>{{ __('admin.an.sample') }}</th><th>{{ __('admin.an.definition') }}</th></tr>
@foreach (['activation', 'trial_conversion', 'paid_conversion', 'churn', 'retention_w1', 'retention_m1'] as $k)
<tr><td><b>{{ __('admin.an.'.$k) }}</b></td><td>{{ $pct($m[$k]) }}</td><td>{{ $m[$k]['of'] > 0 ? $n($m[$k]['n']).' / '.$n($m[$k]['of']) : __('admin.an.not_enough') }}</td><td class="muted" style="font-size:12px">{{ __('admin.an.def.'.$k) }}</td></tr>
@endforeach
</table></div>
</div>

<div class="card">
<h2>{{ __('admin.an.revenue') }}</h2>
@if ($m['revenue'] === [])
<p class="muted">{{ __('admin.an.no_revenue') }}</p>
@else
<div class="tbl"><table>
<tr><th>{{ __('admin.an.currency') }}</th><th>MRR</th><th>ARPU</th><th>{{ __('admin.an.subscriptions') }}</th></tr>
@foreach ($m['revenue'] as $cur => $r)
<tr><td class="ltr">{{ $cur }}</td><td>{{ \App\Support\Money::format($r['mrr'], $cur) }}</td><td>{{ $r['arpu'] === null ? '—' : \App\Support\Money::format($r['arpu'], $cur) }}</td><td>{{ $n($r['subscriptions']) }}</td></tr>
@endforeach
</table></div>
@endif
<p class="help">{{ __('admin.an.revenue_def') }}</p>
</div>
@endsection
