@extends('layouts.page')
@section('title', '#'.$ticket->id)
@section('content')
<div class="row" style="justify-content:space-between">
<h1>#{{ $ticket->id }} — {{ $ticket->subject }}</h1>
<form method="post" action="{{ route('admin.support.status', $ticket) }}" class="row">@csrf
<select name="status" class="btn">@foreach(\App\Models\SupportTicket::STATUSES as $k)<option value="{{ $k }}" @selected($ticket->status === $k)>{{ __('support.status.'.$k) }}</option>@endforeach</select>
<button class="btn sm">{{ __('admin.change_status') }}</button></form>
</div>
<div class="card">
<b>{{ $ticket->user?->name ?? __('support.deleted_user') }}</b> <span class="ltr muted">{{ $ticket->user?->email }}</span>
@if($ticket->user?->telegram_chat_id) · Telegram: <span class="ltr">{{ $ticket->user->telegram_username ? '@'.$ticket->user->telegram_username : $ticket->user->telegram_chat_id }}</span>@endif
@if($ticket->user && app(\App\Services\Billing\Entitlements::class)->canUse($ticket->user, 'priority_support')) · <span class="pill b">{{ __('support.priority_badge') }}</span>@endif
· <span class="pill">{{ $ticket->statusLabel() }}</span>
</div>
@foreach($ticket->messages as $m)
<div class="msg {{ $m->is_staff ? 'staff' : '' }}"><div class="meta">{{ $m->is_staff ? __('support.staff').' ('.($m->user?->name ?? '—').')' : '👤 '.($m->user?->name ?? __('support.user')) }} · {{ \App\Support\LocalDate::dateTime($m->created_at) }}</div>{{ $m->body }}</div>
@endforeach
<form class="card" method="post" action="{{ route('admin.support.reply', $ticket) }}">@csrf
<div class="field"><label>{{ __('admin.reply') }}</label><textarea name="body" required maxlength="5000">{{ old('body') }}</textarea><div class="help">{{ __('admin.reply_help') }}</div></div>
<div class="row"><button class="btn primary">{{ __('admin.send_reply') }}</button><label class="check"><input type="checkbox" name="close" value="1"> {{ __('admin.send_and_close') }}</label></div>
</form>
<a href="{{ route('admin.support') }}">{{ __('support.back') }}</a>
@endsection
