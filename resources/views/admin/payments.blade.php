@extends('layouts.page')
@section('title', __('admin.payments_title'))
@section('content')
<h1>{{ __('admin.payments_title') }}</h1>
<div class="grid">
<div class="stat"><span>{{ __('admin.revenue_month') }}</span><b style="font-size:18px">@forelse ($revenueMonth as $r){{ \App\Support\Money::format((int) $r->total, $r->currency) }} <span class="muted">({{ \App\Support\LocalDate::number($r->n) }})</span><br>@empty — @endforelse</b></div>
<div class="stat"><span>{{ __('admin.revenue_all') }}</span><b style="font-size:18px">@forelse ($revenueAll as $r){{ \App\Support\Money::format((int) $r->total, $r->currency) }} <span class="muted">({{ \App\Support\LocalDate::number($r->n) }})</span><br>@empty — @endforelse</b></div>
<div class="stat"><span>{{ __('admin.providers') }}</span>@foreach ($gateways as $g)<div style="margin-top:6px">{{ __('billing.provider.'.$g->key()) }}: <span class="pill {{ $g->isConfigured() ? 'g' : '' }}">{{ $g->isConfigured() ? __('admin.provider_on') : __('admin.provider_off') }}</span></div>@endforeach</div>
</div>
<p class="help">{{ __('admin.stats.mrr_note') }}</p>
<div class="card tbl"><table>
<tr><th>#</th><th>{{ __('admin.col.date') }}</th><th>{{ __('admin.col.user') }}</th><th>{{ __('admin.col.plan') }}</th><th>{{ __('admin.col.amount') }}</th><th>{{ __('admin.col.provider') }}</th><th>{{ __('admin.col.status') }}</th><th>{{ __('admin.col.reference') }}</th></tr>
@forelse ($payments as $p)
<tr><td class="muted">{{ $p->id }}</td><td>{{ \App\Support\LocalDate::dateTime($p->paid_at ?? $p->created_at) }}</td>
<td>{{ $p->user?->name ?? '—' }}<div class="muted ltr">{{ $p->user?->email }}</div></td>
<td>{{ $p->plan?->localizedName() }} · {{ __('billing.interval.'.$p->billing_interval) }}</td>
<td>{{ \App\Support\Money::format((int) $p->amount, $p->currency) }}</td>
<td>{{ __('billing.provider.'.$p->provider) }}</td>
<td><span class="pill {{ $p->status === 'paid' ? 'g' : ($p->status === 'pending' ? 'y' : 'r') }}">{{ __('billing.payment_status.'.$p->status) }}</span>@if($p->failure_reason)<div class="muted ltr" style="font-size:11px">{{ \Illuminate\Support\Str::limit($p->failure_reason, 80) }}</div>@endif</td>
<td class="ltr">{{ $p->transaction_reference ?? '—' }}@if($p->invoice) · <a href="{{ route('billing.invoice', $p->invoice) }}">{{ $p->invoice->number }}</a>@endif</td></tr>
@empty<tr><td colspan="8" class="muted">{{ __('admin.no_payments') }}</td></tr>@endforelse
</table></div>
{{ $payments->links('pagination::simple-default') }}
@endsection
