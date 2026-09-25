@extends('layouts.page')
@section('title', 'تیکت #'.$ticket->id)
@section('content')
<div class="row" style="justify-content:space-between">
<h1>#{{ $ticket->id }} — {{ $ticket->subject }}</h1>
<form method="post" action="{{ route('admin.support.status', $ticket) }}" class="row">@csrf
<select name="status" class="btn">@foreach(\App\Models\SupportTicket::STATUSES as $k => $l)<option value="{{ $k }}" @selected($ticket->status === $k)>{{ $l }}</option>@endforeach</select>
<button class="btn sm">تغییر وضعیت</button></form>
</div>
<div class="card">
<b>{{ $ticket->user?->name ?? 'کاربر حذف‌شده' }}</b> <span class="ltr muted">{{ $ticket->user?->email }}</span>
@if($ticket->user?->telegram_chat_id) · Telegram: <span class="ltr">{{ $ticket->user->telegram_username ? '@'.$ticket->user->telegram_username : $ticket->user->telegram_chat_id }}</span>@endif
· <span class="pill">{{ $ticket->statusLabel() }}</span>
</div>
@foreach($ticket->messages as $m)
<div class="msg {{ $m->is_staff ? 'staff' : '' }}"><div class="meta">{{ $m->is_staff ? '🛟 پشتیبانی ('.($m->user?->name ?? '—').')' : '👤 '.($m->user?->name ?? 'کاربر') }} · <span class="ltr">{{ $m->created_at?->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</span></div>{{ $m->body }}</div>
@endforeach
<form class="card" method="post" action="{{ route('admin.support.reply', $ticket) }}">@csrf
<div class="field"><label>پاسخ</label><textarea name="body" required maxlength="5000">{{ old('body') }}</textarea><div class="help">بعد از ارسال، کاربر از طریق Telegram (اگر متصل باشد) و ایمیل مطلع می‌شود.</div></div>
<div class="row"><button class="btn primary">ارسال پاسخ</button><label class="check"><input type="checkbox" name="close" value="1"> ارسال و بستن تیکت</label></div>
</form>
<a href="{{ route('admin.support') }}">← بازگشت به تیکت‌ها</a>
@endsection
