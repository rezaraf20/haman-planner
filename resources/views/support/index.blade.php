@extends('layouts.page')
@section('title', __('support.title'))
@section('content')
<h1>{{ __('support.title') }}</h1>
@if($note !== '')<div class="card" style="white-space:pre-wrap;line-height:1.9">{{ $note }}</div>@endif
<form class="card" method="post" action="{{ route('support.store') }}">@csrf
<h2>{{ __('support.new_ticket') }}</h2>
<div class="field"><label>{{ __('support.subject') }}</label><input type="text" name="subject" value="{{ old('subject') }}" maxlength="200" required></div>
<div class="field"><label>{{ __('support.message') }}</label><textarea name="body" maxlength="5000" required>{{ old('body') }}</textarea></div>
<button class="btn primary">{{ __('support.send_ticket') }}</button>
</form>
<div class="card tbl">
<h2>{{ __('support.my_tickets') }}</h2>
<table><tr><th>#</th><th>{{ __('support.subject') }}</th><th>{{ __('planner.fields.status') }}</th><th>{{ __('support.last_message') }}</th></tr>
@forelse($tickets as $t)
<tr><td class="muted">{{ $t->id }}</td><td><a href="{{ route('support.show', $t) }}">{{ $t->subject }}</a></td>
<td><span class="pill {{ $t->status === 'answered' ? 'g' : ($t->status === 'open' ? 'y' : '') }}">{{ $t->statusLabel() }}</span></td>
<td>{{ \App\Support\LocalDate::dateTime($t->last_reply_at) }}</td></tr>
@empty<tr><td colspan="4" class="muted">{{ __('support.no_tickets') }}</td></tr>@endforelse
</table></div>
{{ $tickets->links('pagination::simple-default') }}
@endsection
