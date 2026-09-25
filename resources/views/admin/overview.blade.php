@extends('layouts.page')
@section('title', 'داشبورد مدیریت')
@section('content')
<h1>داشبورد مدیریت</h1>
<div class="grid">
<div class="stat"><span>کل کاربران</span><b>{{ number_format($stats['users']) }}</b></div>
<div class="stat"><span>ثبت‌نام ۷ روز اخیر</span><b>{{ number_format($stats['new_7']) }}</b></div>
<div class="stat"><span>ثبت‌نام ۳۰ روز اخیر</span><b>{{ number_format($stats['new_30']) }}</b></div>
<div class="stat"><span>متصل به Telegram</span><b>{{ number_format($stats['telegram']) }}</b></div>
<div class="stat"><span>فعال در ۲۴ ساعت</span><b>{{ number_format($stats['seen_1']) }}</b></div>
<div class="stat"><span>فعال در ۷ روز</span><b>{{ number_format($stats['seen_7']) }}</b></div>
<div class="stat"><span>فعال در ۳۰ روز</span><b>{{ number_format($stats['seen_30']) }}</b></div>
<div class="stat"><span>کل کارهای ثبت‌شده</span><b>{{ number_format($stats['tasks']) }}</b></div>
<div class="stat"><span>کارهای ۷ روز اخیر</span><b>{{ number_format($stats['tasks_7']) }}</b></div>
<div class="stat"><span>تیکت‌های باز</span><b><a href="{{ route('admin.support') }}">{{ number_format($stats['open_tickets']) }}</a></b></div>
<div class="stat"><span>حساب‌های فعال / مدیر</span><b>{{ $stats['active_accounts'] }} / {{ $stats['admins'] }}</b></div>
</div>

<div class="card" style="margin-top:16px">
<h2>ثبت‌نام روزانه (۱۴ روز اخیر)</h2>
@php($max = max(1, max($signups)))
<div class="bars">@foreach($signups as $day => $c)<div style="height:{{ max(3, (int) round($c / $max * 100)) }}%" title="{{ $day }}: {{ $c }}"><span>{{ $c }}</span></div>@endforeach</div>
<div class="row muted" style="justify-content:space-between;font-size:11px;margin-top:6px"><span class="ltr">{{ array_key_first($signups) }}</span><span class="ltr">{{ array_key_last($signups) }}</span></div>
<p class="help">«فعال» یعنی کاربر در آن بازه از پنل وب یا ربات Telegram استفاده کرده است.</p>
</div>

<div class="card">
<div class="row" style="justify-content:space-between"><h2>آخرین ثبت‌نام‌ها</h2><a class="btn sm" href="{{ route('admin.users') }}">همه کاربران</a></div>
<div class="tbl"><table>
<tr><th>نام</th><th>ایمیل</th><th>Telegram</th><th>ثبت‌نام</th></tr>
@forelse($recent as $u)
<tr><td>{{ $u->name }}</td><td class="ltr">{{ $u->email }}</td><td>@if($u->telegram_chat_id)<span class="pill g">{{ $u->telegram_username ? '@'.$u->telegram_username : 'متصل' }}</span>@else<span class="muted">—</span>@endif</td><td class="ltr">{{ $u->created_at?->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</td></tr>
@empty<tr><td colspan="4" class="muted">کاربری نیست.</td></tr>@endforelse
</table></div>
</div>

<div class="card">
<div class="row" style="justify-content:space-between"><h2>تیکت‌های باز</h2><a class="btn sm" href="{{ route('admin.support') }}">همه تیکت‌ها</a></div>
@forelse($tickets as $t)
<div class="row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:8px 0"><a href="{{ route('admin.support.show', $t) }}">#{{ $t->id }} — {{ $t->subject }}</a><span class="muted">{{ $t->user?->name }} · {{ $t->last_reply_at?->timezone('Asia/Tehran')->format('m/d H:i') }}</span></div>
@empty<p class="muted">تیکت بازی وجود ندارد.</p>@endforelse
</div>
@endsection
