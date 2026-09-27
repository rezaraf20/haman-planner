@extends('layouts.page')
@section('title', __('admin.dashboard'))
@section('content')
@php($n = fn ($v) => \App\Support\LocalDate::number($v))
<h1>{{ __('admin.dashboard') }}</h1>
<div class="grid">
@foreach (['users','new_7','new_30','telegram','seen_1','seen_7','seen_30','paying','trialing','ai_month','ai_30','tasks','tasks_7'] as $k)
<div class="stat"><span>{{ __('admin.stats.'.$k) }}</span><b>{{ $n($stats[$k]) }}</b></div>
@endforeach
<div class="stat"><span>{{ __('admin.stats.open_tickets') }}</span><b><a href="{{ route('admin.support') }}">{{ $n($stats['open_tickets']) }}</a></b></div>
<div class="stat"><span>{{ __('admin.stats.accounts') }}</span><b>{{ $n($stats['active_accounts']) }} / {{ $n($stats['admins']) }}</b></div>
<div class="stat"><span>{{ __('admin.stats.revenue_30') }}</span><b style="font-size:18px">@forelse ($revenue30 as $cur => $total){{ \App\Support\Money::format((int) $total, $cur) }}<br>@empty — @endforelse</b></div>
</div>

<div class="card" style="margin-top:16px">
<h2>{{ __('admin.signups_chart') }}</h2>
@php($max = max(1, max($signups)))
<div class="bars">@foreach ($signups as $day => $c)<div style="height:{{ max(3, (int) round($c / $max * 100)) }}%" title="{{ $day }}: {{ $c }}"><span>{{ $n($c) }}</span></div>@endforeach</div>
<div class="row muted" style="justify-content:space-between;font-size:11px;margin-top:6px"><span>{{ \App\Support\LocalDate::date(array_key_first($signups)) }}</span><span>{{ \App\Support\LocalDate::date(array_key_last($signups)) }}</span></div>
<p class="help">{{ __('admin.active_hint') }}</p>
</div>

<div class="card">
<h2>{{ __('admin.funnel') }}</h2>
@php($top = max(1, (int) ($funnel['registered'] ?? 0), ...array_map('intval', array_values($funnel) ?: [0])))
@foreach (\App\Services\Analytics\ProductEvents::FUNNEL as $ev)
<div class="row" style="margin:6px 0"><span style="min-width:170px">{{ __('admin.events.'.$ev) }}</span><div class="meter" style="flex:1"><i style="width:{{ (int) round(($funnel[$ev] ?? 0) / $top * 100) }}%"></i></div><b style="min-width:40px;text-align:end">{{ $n($funnel[$ev] ?? 0) }}</b></div>
@endforeach
</div>

<div class="card">
<div class="row" style="justify-content:space-between"><h2>{{ __('admin.recent_signups') }}</h2><a class="btn sm" href="{{ route('admin.users') }}">{{ __('admin.all_users') }}</a></div>
<div class="tbl"><table>
<tr><th>{{ __('settings.name') }}</th><th>{{ __('settings.email') }}</th><th>{{ __('admin.col.telegram') }}</th><th>{{ __('common.language') }}</th><th>{{ __('admin.col.registered') }}</th></tr>
@forelse ($recent as $u)
<tr><td>{{ $u->name }}</td><td class="ltr">{{ $u->email }}</td><td>@if($u->telegram_chat_id)<span class="pill g ltr">{{ $u->telegram_username ? '@'.$u->telegram_username : '✓' }}</span>@else<span class="muted">—</span>@endif</td><td>{{ \App\Support\Locales::label($u->preferredLocale()) }}</td><td>{{ \App\Support\LocalDate::dateTime($u->created_at) }}</td></tr>
@empty<tr><td colspan="5" class="muted">{{ __('admin.no_users') }}</td></tr>@endforelse
</table></div>
</div>

<div class="card">
<div class="row" style="justify-content:space-between"><h2>{{ __('admin.open_tickets') }}</h2><a class="btn sm" href="{{ route('admin.support') }}">{{ __('admin.all_tickets') }}</a></div>
@forelse ($tickets as $t)
<div class="row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:8px 0"><a href="{{ route('admin.support.show', $t) }}">#{{ $t->id }} — {{ $t->subject }}</a><span class="muted">{{ $t->user?->name }} · {{ \App\Support\LocalDate::dateTime($t->last_reply_at) }}</span></div>
@empty<p class="muted">{{ __('admin.no_tickets') }}</p>@endforelse
</div>
@endsection
