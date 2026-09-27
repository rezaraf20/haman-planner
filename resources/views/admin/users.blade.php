@extends('layouts.page')
@section('title', __('admin.nav.users'))
@section('content')
<div class="row" style="justify-content:space-between"><h1>{{ __('admin.users_title', ['count' => \App\Support\LocalDate::number($users->total())]) }}</h1><a class="btn" href="{{ route('admin.users.export') }}">{{ __('admin.export_csv') }}</a></div>
<form class="card row" method="get" action="{{ route('admin.users') }}">
<div class="field" style="flex:1;min-width:220px;margin:0"><input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.search_users') }}"></div>
<div class="field" style="margin:0"><select name="filter">@foreach (__('admin.filters') as $k => $label)<option value="{{ $k }}" @selected($filter === $k)>{{ $label }}</option>@endforeach</select></div>
<button class="btn primary">{{ __('common.filter') }}</button>
</form>
<div class="card tbl"><table>
<tr><th>#</th><th>{{ __('admin.col.name_email') }}</th><th>{{ __('admin.col.telegram') }}</th><th>{{ __('admin.col.plan') }}</th><th>{{ __('admin.col.registered') }}</th><th>{{ __('admin.col.last_seen') }}</th><th>{{ __('admin.col.tasks') }}</th><th>{{ __('admin.col.status') }}</th><th></th></tr>
@forelse ($users as $u)
@php($sub = $subs[$u->id] ?? null)
<tr>
<td class="muted">{{ $u->id }}</td>
<td><b>{{ $u->name }}</b>@if($u->is_admin) <span class="pill b">{{ __('admin.admin_badge') }}</span>@endif<div class="ltr muted">{{ $u->email }}</div><div class="muted" style="font-size:11px">{{ \App\Support\Locales::label($u->preferredLocale()) }} · <span class="ltr">{{ $u->preferredTimezone() }}</span></div></td>
<td>@if($u->telegram_chat_id)
@if($u->telegram_username)<a class="ltr" href="https://t.me/{{ $u->telegram_username }}" target="_blank" rel="noopener">{{ '@'.$u->telegram_username }}</a><br>@endif
<span class="muted ltr">ID: {{ $u->telegram_chat_id }}</span><div class="muted" style="font-size:11px">{{ __('admin.linked_on', ['date' => \App\Support\LocalDate::date($u->telegram_linked_at)]) }}</div>
@else<span class="muted">{{ __('admin.not_connected') }}</span>@endif</td>
<td>{{ $sub?->plan?->localizedName() ?? $defaultPlan?->localizedName() ?? '—' }}@if($sub)<div class="muted" style="font-size:11px">{{ __('billing.status.'.$sub->status) }} · {{ \App\Support\LocalDate::date($sub->current_period_end) }}</div>@endif
<details style="margin-top:4px"><summary class="muted" style="cursor:pointer;font-size:12px">{{ __('admin.grant') }}</summary>
<form method="post" action="{{ route('admin.users.grant', $u) }}" class="row" style="margin-top:6px">@csrf
<select name="plan" class="btn sm">@foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->localizedName() }}</option>@endforeach</select>
<input name="months" type="number" min="1" max="36" value="1" class="btn sm" style="width:64px"> <span class="muted">{{ __('admin.grant_months') }}</span>
<button class="btn sm" onclick="return confirm(@js(__('admin.confirm')))">{{ __('common.confirm') }}</button></form></details></td>
<td>{{ \App\Support\LocalDate::dateTime($u->created_at) }}</td>
<td>{{ $u->last_seen_at ? \App\Support\LocalDate::dateTime($u->last_seen_at) : '—' }}</td>
<td>{{ \App\Support\LocalDate::number((int) $u->tasks_count) }}</td>
<td>@if($u->is_active)<span class="pill g">{{ __('common.active') }}</span>@else<span class="pill r">{{ __('common.inactive') }}</span>@endif</td>
<td>@if($u->id !== auth()->id())
<form method="post" action="{{ route('admin.users.toggle', $u) }}" style="display:inline">@csrf<input type="hidden" name="field" value="is_active"><button class="btn sm {{ $u->is_active ? 'danger' : '' }}" onclick="return confirm(@js(__('admin.confirm')))">{{ $u->is_active ? __('admin.deactivate') : __('admin.activate') }}</button></form>
<form method="post" action="{{ route('admin.users.toggle', $u) }}" style="display:inline">@csrf<input type="hidden" name="field" value="is_admin"><button class="btn sm" onclick="return confirm(@js(__('admin.confirm_role')))">{{ $u->is_admin ? __('admin.remove_admin') : __('admin.make_admin') }}</button></form>
@else<span class="muted">{{ __('common.you') }}</span>@endif</td>
</tr>
@empty<tr><td colspan="9" class="muted">{{ __('admin.no_users') }}</td></tr>@endforelse
</table></div>
{{ $users->links('pagination::simple-default') }}
<p class="help">{{ __('admin.privacy_note') }}</p>
@endsection
