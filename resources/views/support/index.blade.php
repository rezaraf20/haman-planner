@extends('layouts.page')
@section('title', 'پشتیبانی')
@section('content')
<h1>پشتیبانی</h1>
@if($note !== '')<div class="card" style="white-space:pre-wrap;line-height:1.9">{{ $note }}</div>@endif
<form class="card" method="post" action="{{ route('support.store') }}">@csrf
<h2>ثبت تیکت جدید</h2>
<div class="field"><label>موضوع</label><input type="text" name="subject" value="{{ old('subject') }}" maxlength="200" required></div>
<div class="field"><label>متن پیام</label><textarea name="body" maxlength="5000" required>{{ old('body') }}</textarea></div>
<button class="btn primary">ارسال تیکت</button>
</form>
<div class="card tbl">
<h2>تیکت‌های من</h2>
<table><tr><th>#</th><th>موضوع</th><th>وضعیت</th><th>آخرین پیام</th></tr>
@forelse($tickets as $t)
<tr><td class="muted">{{ $t->id }}</td><td><a href="{{ route('support.show', $t) }}">{{ $t->subject }}</a></td>
<td><span class="pill {{ $t->status === 'answered' ? 'g' : ($t->status === 'open' ? 'y' : '') }}">{{ $t->statusLabel() }}</span></td>
<td class="ltr">{{ $t->last_reply_at?->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</td></tr>
@empty<tr><td colspan="4" class="muted">هنوز تیکتی ثبت نکرده‌اید.</td></tr>@endforelse
</table></div>
{{ $tickets->links('pagination::simple-default') }}
@endsection
