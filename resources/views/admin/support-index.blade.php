@extends('layouts.page')
@section('title', 'تیکت‌ها')
@section('content')
<h1>تیکت‌های پشتیبانی</h1>
<div class="sub">
@foreach(\App\Models\SupportTicket::STATUSES as $key => $label)
<a href="{{ route('admin.support', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
@endforeach
<a href="{{ route('admin.support', ['status' => 'all']) }}" class="{{ $status === 'all' ? 'on' : '' }}">همه</a>
</div>
<div class="card tbl"><table>
<tr><th>#</th><th>موضوع</th><th>کاربر</th><th>وضعیت</th><th>آخرین پیام</th></tr>
@forelse($tickets as $t)
<tr><td class="muted">{{ $t->id }}</td><td><a href="{{ route('admin.support.show', $t) }}">{{ $t->subject }}</a></td><td>{{ $t->user?->name ?? '—' }}<div class="muted ltr">{{ $t->user?->email }}</div></td>
<td><span class="pill {{ $t->status === 'open' ? 'y' : ($t->status === 'answered' ? 'g' : '') }}">{{ $t->statusLabel() }}</span></td>
<td class="ltr">{{ $t->last_reply_at?->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</td></tr>
@empty<tr><td colspan="5" class="muted">تیکتی نیست.</td></tr>@endforelse
</table></div>
{{ $tickets->links('pagination::simple-default') }}
@endsection
