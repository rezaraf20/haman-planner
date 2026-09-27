@extends('layouts.page')
@section('title', __('admin.tickets_title'))
@section('content')
<h1>{{ __('admin.tickets_title') }}</h1>
<div class="sub">
@foreach (\App\Models\SupportTicket::STATUSES as $key)
<a href="{{ route('admin.support', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ __('support.status.'.$key) }} ({{ \App\Support\LocalDate::number($counts[$key] ?? 0) }})</a>
@endforeach
<a href="{{ route('admin.support', ['status' => 'all']) }}" class="{{ $status === 'all' ? 'on' : '' }}">{{ __('common.all') }}</a>
</div>
<div class="card tbl"><table>
<tr><th>#</th><th>{{ __('admin.col.subject') }}</th><th>{{ __('admin.col.user') }}</th><th>{{ __('admin.col.status') }}</th><th>{{ __('admin.col.last_message') }}</th></tr>
@forelse($tickets as $t)
<tr><td class="muted">{{ $t->id }}</td><td><a href="{{ route('admin.support.show', $t) }}">{{ $t->subject }}</a></td><td>{{ $t->user?->name ?? '—' }}<div class="muted ltr">{{ $t->user?->email }}</div></td>
<td><span class="pill {{ $t->status === 'open' ? 'y' : ($t->status === 'answered' ? 'g' : '') }}">{{ $t->statusLabel() }}</span></td>
<td>{{ \App\Support\LocalDate::dateTime($t->last_reply_at) }}</td></tr>
@empty<tr><td colspan="5" class="muted">{{ __('admin.no_tickets') }}</td></tr>@endforelse
</table></div>
{{ $tickets->links('pagination::simple-default') }}
@endsection
