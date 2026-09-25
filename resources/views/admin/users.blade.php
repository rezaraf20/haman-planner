@extends('layouts.page')
@section('title', 'کاربران')
@section('content')
<div class="row" style="justify-content:space-between"><h1>کاربران ({{ number_format($users->total()) }})</h1><a class="btn" href="{{ route('admin.users.export') }}">⬇ خروجی Excel (CSV)</a></div>
<form class="card row" method="get" action="{{ route('admin.users') }}">
<div class="field" style="flex:1;min-width:220px;margin:0"><input type="search" name="q" value="{{ $q }}" placeholder="جستجو: نام، ایمیل، username یا Chat ID تلگرام"></div>
<div class="field" style="margin:0"><select name="filter">
<option value="">همه</option>
<option value="telegram" @selected($filter==='telegram')>متصل به Telegram</option>
<option value="no_telegram" @selected($filter==='no_telegram')>بدون Telegram</option>
<option value="inactive" @selected($filter==='inactive')>غیرفعال</option>
<option value="admins" @selected($filter==='admins')>مدیرها</option>
</select></div>
<button class="btn primary">فیلتر</button>
</form>
<div class="card tbl"><table>
<tr><th>#</th><th>نام / ایمیل</th><th>Telegram</th><th>ثبت‌نام</th><th>آخرین فعالیت</th><th>تعداد کار</th><th>وضعیت</th><th></th></tr>
@forelse($users as $u)
<tr>
<td class="muted">{{ $u->id }}</td>
<td><b>{{ $u->name }}</b>@if($u->is_admin) <span class="pill b">مدیر</span>@endif<div class="ltr muted">{{ $u->email }}</div></td>
<td>@if($u->telegram_chat_id)
@if($u->telegram_username)<a class="ltr" href="https://t.me/{{ $u->telegram_username }}" target="_blank" rel="noopener">{{ '@'.$u->telegram_username }}</a><br>@endif
<span class="muted ltr">ID: {{ $u->telegram_chat_id }}</span><div class="muted" style="font-size:11px">اتصال: {{ $u->telegram_linked_at?->timezone('Asia/Tehran')->format('Y-m-d') }}</div>
@else<span class="muted">متصل نیست</span>@endif</td>
<td class="ltr">{{ $u->created_at?->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</td>
<td class="ltr">{{ $u->last_seen_at ? $u->last_seen_at->timezone('Asia/Tehran')->format('Y-m-d H:i') : '—' }}</td>
<td>{{ number_format((int) $u->tasks_count) }}</td>
<td>@if($u->is_active)<span class="pill g">فعال</span>@else<span class="pill r">غیرفعال</span>@endif</td>
<td>@if($u->id !== auth()->id())
<form method="post" action="{{ route('admin.users.toggle', $u) }}" style="display:inline">@csrf<input type="hidden" name="field" value="is_active"><button class="btn sm {{ $u->is_active ? 'danger' : '' }}" onclick="return confirm('مطمئن هستید؟')">{{ $u->is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی' }}</button></form>
<form method="post" action="{{ route('admin.users.toggle', $u) }}" style="display:inline">@csrf<input type="hidden" name="field" value="is_admin"><button class="btn sm" onclick="return confirm('نقش این کاربر تغییر کند؟')">{{ $u->is_admin ? 'حذف مدیر' : 'مدیر شود' }}</button></form>
@else<span class="muted">شما</span>@endif</td>
</tr>
@empty<tr><td colspan="8" class="muted">کاربری پیدا نشد.</td></tr>@endforelse
</table></div>
{{ $users->links('pagination::simple-default') }}
<p class="help">برای حفظ حریم خصوصی، محتوای برنامه‌ی کاربران (کارها، یادداشت‌ها و …) در پنل مدیریت نمایش داده نمی‌شود؛ فقط اطلاعات حساب و آمار استفاده.</p>
@endsection
