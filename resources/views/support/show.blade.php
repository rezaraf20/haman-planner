@extends('layouts.page')
@section('title', '#'.$ticket->id)
@section('content')
<div class="row" style="justify-content:space-between">
<h1>#{{ $ticket->id }} — {{ $ticket->subject }}</h1>
<span class="pill {{ $ticket->status === 'answered' ? 'g' : ($ticket->status === 'open' ? 'y' : '') }}">{{ $ticket->statusLabel() }}</span>
</div>
@foreach($ticket->messages as $m)
<div class="msg {{ $m->is_staff ? 'staff' : '' }}"><div class="meta">{{ $m->is_staff ? __('support.staff') : __('support.you') }} · {{ \App\Support\LocalDate::dateTime($m->created_at) }}</div>{{ $m->body }}</div>
@endforeach
<form class="card" method="post" action="{{ route('support.reply', $ticket) }}">@csrf
<div class="field"><label>{{ $ticket->status === 'closed' ? __('support.reopen_message') : __('support.new_message') }}</label><textarea name="body" required maxlength="5000">{{ old('body') }}</textarea></div>
<div class="row"><button class="btn primary">{{ __('support.send') }}</button></div>
</form>
@if($ticket->status !== 'closed')
<form method="post" action="{{ route('support.close', $ticket) }}">@csrf<button class="btn">{{ __('support.close_ticket') }}</button></form>
@endif
<p><a href="{{ route('support.index') }}">{{ __('support.back') }}</a></p>
@endsection
