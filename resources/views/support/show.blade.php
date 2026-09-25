@extends('layouts.page')
@section('title', 'تیکت #'.$ticket->id)
@section('content')
<div class="row" style="justify-content:space-between">
<h1>#{{ $ticket->id }} — {{ $ticket->subject }}</h1>
<span class="pill {{ $ticket->status === 'answered' ? 'g' : ($ticket->status === 'open' ? 'y' : '') }}">{{ $ticket->statusLabel() }}</span>
</div>
@foreach($ticket->messages as $m)
<div class="msg {{ $m->is_staff ? 'staff' : '' }}"><div class="meta">{{ $m->is_staff ? '🛟 پشتیبانی' : '👤 شما' }} · <span class="ltr">{{ $m->created_at?->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</span></div>{{ $m->body }}</div>
@endforeach
<form class="card" method="post" action="{{ route('support.reply', $ticket) }}">@csrf
<div class="field"><label>{{ $ticket->status === 'closed' ? 'ارسال پیام (تیکت دوباره باز می‌شود)' : 'پیام جدید' }}</label><textarea name="body" required maxlength="5000">{{ old('body') }}</textarea></div>
<div class="row"><button class="btn primary">ارسال</button></div>
</form>
@if($ticket->status !== 'closed')
<form method="post" action="{{ route('support.close', $ticket) }}">@csrf<button class="btn">بستن تیکت (مشکل حل شد)</button></form>
@endif
<p><a href="{{ route('support.index') }}">← بازگشت به تیکت‌ها</a></p>
@endsection
