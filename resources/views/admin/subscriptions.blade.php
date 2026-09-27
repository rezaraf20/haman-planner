@extends('layouts.page')
@section('title', __('admin.subscriptions_title'))
@section('content')
<h1>{{ __('admin.subscriptions_title') }}</h1>
<div class="sub">
<a href="{{ route('admin.subscriptions') }}" class="{{ $status === '' ? 'on' : '' }}">{{ __('common.all') }}</a>
@foreach (\App\Models\Subscription::STATUSES as $st)
<a href="{{ route('admin.subscriptions', ['status' => $st]) }}" class="{{ $status === $st ? 'on' : '' }}">{{ __('billing.status.'.$st) }} ({{ \App\Support\LocalDate::number($counts[$st] ?? 0) }})</a>
@endforeach
</div>
<div class="card tbl"><table>
<tr><th>#</th><th>{{ __('admin.col.user') }}</th><th>{{ __('admin.col.plan') }}</th><th>{{ __('admin.col.status') }}</th><th>{{ __('admin.col.provider') }}</th><th>{{ __('admin.col.period_end') }}</th><th>{{ __('admin.col.date') }}</th></tr>
@forelse ($subs as $s)
<tr><td class="muted">{{ $s->id }}</td><td>{{ $s->user?->name ?? '—' }}<div class="muted ltr">{{ $s->user?->email }}</div></td>
<td>{{ $s->plan?->localizedName() }}@if($s->billing_interval) · {{ __('billing.interval.'.$s->billing_interval) }}@endif</td>
<td><span class="pill {{ in_array($s->status, ['active','trialing'], true) ? 'g' : ($s->status === 'canceled' ? 'y' : '') }}">{{ __('billing.status.'.$s->status) }}</span></td>
<td>{{ __('billing.provider.'.($s->provider ?? 'manual')) }}</td>
<td>{{ \App\Support\LocalDate::date($s->current_period_end) }}</td>
<td>{{ \App\Support\LocalDate::date($s->created_at) }}</td></tr>
@empty<tr><td colspan="7" class="muted">{{ __('admin.no_subscriptions') }}</td></tr>@endforelse
</table></div>
{{ $subs->links('pagination::simple-default') }}
@endsection
