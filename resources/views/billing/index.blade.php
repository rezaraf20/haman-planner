@extends('layouts.page')
@section('title', __('billing.title'))
@push('styles')
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--s4)}
.plan{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);padding:var(--s5);display:flex;flex-direction:column;gap:var(--s2)}.plan.current{border-color:var(--ink-900);box-shadow:0 0 0 1px var(--ink-900)}
.plan-name{font-weight:var(--w-bold);font-size:var(--fs-lg);display:flex;gap:8px;align-items:center}.plan-desc{color:var(--text-3);line-height:1.8;min-height:48px}
.plan-price{font-size:24px;font-weight:var(--w-bold);font-variant-numeric:tabular-nums}.plan-price small{font-size:var(--fs-sm);color:var(--text-3);font-weight:var(--w-medium)}
.plan-features{list-style:none;padding:0;margin:4px 0;line-height:2;font-size:var(--fs-sm)}.plan-features .no{color:var(--text-3);opacity:.7}.plan-actions{display:grid;gap:var(--s2);margin-top:auto;padding-top:var(--s2)}
.pay-caption{font-size:var(--fs-xs);font-weight:var(--w-semibold);color:var(--text-3)}
.toggle{display:inline-flex;border:1px solid var(--line-strong);border-radius:var(--r);overflow:hidden;background:var(--surface)}.toggle a{padding:6px 14px;color:var(--text-2);font-weight:var(--w-semibold);text-decoration:none}.toggle a.on{background:var(--ink-900);color:#fff}
@endpush
@section('content')
@php($sub = $summary['subscription'])
@php($plan = $summary['plan'])
<h1>{{ __('billing.title') }}</h1>
@if (session('billing_error'))<div class="err">{{ session('billing_error') }}</div>@endif

<div class="card">
<h2>{{ __('billing.current_plan') }}</h2>
<div class="row" style="justify-content:space-between">
<div>
<b style="font-size:20px">{{ $plan?->localizedName() ?? __('billing.free') }}</b>
<span class="pill {{ $sub ? ($sub->status === 'past_due' ? 'r' : ($sub->status === 'canceled' ? 'y' : 'g')) : '' }}">{{ __('billing.status.'.($sub?->status ?? 'free')) }}</span>
@if ($sub)
<div class="muted" style="margin-top:6px">
@if ($sub->status === 'trialing'){{ __('billing.trial_ends_on', ['date' => \App\Support\LocalDate::date($sub->current_period_end)]) }}
@elseif ($sub->status === 'canceled'){{ __('billing.ends_on', ['date' => \App\Support\LocalDate::date($sub->current_period_end)]) }}
@elseif ($sub->status === 'past_due'){{ __('billing.past_due_notice', ['date' => \App\Support\LocalDate::date($sub->pastDueUntil())]) }}
@elseif ($sub->auto_renew){{ __('billing.auto_renew_on', ['date' => \App\Support\LocalDate::date($sub->current_period_end)]) }}
@else{{ __('billing.renews_on', ['date' => \App\Support\LocalDate::date($sub->current_period_end)]) }}@endif
@if ($sub->provider === 'manual') · {{ __('billing.manual') }}@endif
</div>
@endif
</div>
<div class="row">
@if ($sub && $sub->status === 'canceled')
<form method="post" action="{{ route('billing.resume') }}">@csrf<button class="btn">{{ __('billing.resume') }}</button></form>
@elseif ($sub)
<form method="post" action="{{ route('billing.cancel') }}" onsubmit="return confirm(@js(__('billing.cancel_confirm')))">@csrf<button class="btn danger">{{ __('billing.cancel') }}</button></form>
@endif
</div>
</div>
<h2 style="margin-top:18px">{{ __('billing.usage') }}</h2>
<div class="grid">
@foreach ($summary['metrics'] as $m => $u)
@php($pct = $u['limit'] ? min(100, (int) round($u['used'] / max(1, $u['limit']) * 100)) : 0)
<div class="stat"><span>{{ __('billing.metric.'.$m) }}</span>
<div style="margin:6px 0">{{ $u['limit'] === null ? __('billing.usage_unlimited', ['used' => \App\Support\LocalDate::number($u['used'])]) : __('billing.usage_of', ['used' => \App\Support\LocalDate::number($u['used']), 'limit' => \App\Support\LocalDate::number($u['limit'])]) }}</div>
@if ($u['limit'] !== null)<div class="meter"><i class="{{ $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '') }}" style="width:{{ $pct }}%"></i></div>@endif
</div>
@endforeach
</div>
</div>

<div class="card">
<div class="row" style="justify-content:space-between;margin-bottom:12px">
<h2 style="margin:0">{{ __('billing.plans') }}</h2>
<div class="toggle"><a href="{{ route('billing.index', ['interval' => 'monthly']) }}" class="{{ $interval === 'monthly' ? 'on' : '' }}">{{ __('billing.monthly') }}</a><a href="{{ route('billing.index', ['interval' => 'yearly']) }}" class="{{ $interval === 'yearly' ? 'on' : '' }}">{{ __('billing.yearly') }}</a></div>
</div>
@include('billing._plans', ['actions' => true, 'currentPlanId' => $sub?->plan_id ?? $plan?->id])
<p class="help" style="margin-top:14px">{{ __('billing.switch_note') }} {{ __('billing.downgrade_note') }}</p>
</div>

<div class="card tbl">
<h2>{{ __('billing.history') }}</h2>
<table>
<tr><th>{{ __('billing.date') }}</th><th>{{ __('billing.plan') }}</th><th>{{ __('billing.amount') }}</th><th>{{ __('planner.fields.status') }}</th><th>{{ __('billing.reference') }}</th><th>{{ __('billing.invoice') }}</th></tr>
@forelse ($payments as $pay)
<tr>
<td>{{ \App\Support\LocalDate::dateTime($pay->paid_at ?? $pay->created_at) }}</td>
<td>{{ $pay->plan?->localizedName() ?? '—' }} · {{ __('billing.interval.'.$pay->billing_interval) }}</td>
<td>{{ \App\Support\Money::format((int) $pay->amount, $pay->currency) }}</td>
<td><span class="pill {{ $pay->status === 'paid' ? 'g' : ($pay->status === 'pending' ? 'y' : 'r') }}">{{ __('billing.payment_status.'.$pay->status) }}</span></td>
<td class="ltr">{{ $pay->transaction_reference ?? '—' }}</td>
<td>@if ($pay->invoice)<a href="{{ route('billing.invoice', $pay->invoice) }}">{{ $pay->invoice->number }}</a>@else — @endif</td>
</tr>
@empty
<tr><td colspan="6" class="muted">{{ __('billing.no_payments') }}</td></tr>
@endforelse
</table>
</div>
@endsection
