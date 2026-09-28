@extends('layouts.page')
@section('title', __('settings.title'))
@push('styles')
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:860px){.cols{grid-template-columns:1fr}}
.code{font-size:28px;letter-spacing:5px;font-weight:900;background:#f1f5f9;padding:14px;border-radius:12px;text-align:center;margin:14px 0;direction:ltr}
.tg{display:inline-block;background:#229ED9;color:#fff;border-radius:10px;padding:11px 16px;font-weight:700}.tg:hover{text-decoration:none}
.danger-zone{border-color:#f5c2c0}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0}dt{color:var(--muted)}dd{margin:0}
@endpush
@section('content')
@php($prefs = $user->allPreferences())
<h1>{{ __('settings.title') }}</h1>
<div class="cols">
<div>
<form class="card" method="post" action="{{ route('account.profile') }}">@csrf
<h2>{{ __('settings.profile') }}</h2>
<div class="field"><label>{{ __('settings.name') }}</label><input name="name" value="{{ old('name', $user->name) }}" maxlength="120" required></div>
<div class="field"><label>{{ __('settings.email') }}</label><input type="email" name="email" value="{{ old('email', $user->email) }}" required></div>
<div class="field"><label>{{ __('settings.current_password') }}</label><input type="password" name="current_password" autocomplete="current-password"><div class="help">{{ __('settings.email_change_help') }}</div></div>
<button class="btn primary">{{ __('settings.save_profile') }}</button>
</form>

<form class="card" method="post" action="{{ route('account.preferences') }}">@csrf
<h2>{{ __('settings.preferences') }}</h2>
<div class="field"><label>{{ __('settings.language') }}</label>
<select name="locale">@foreach(\App\Support\Locales::SUPPORTED as $l)<option value="{{ $l }}" @selected($user->preferredLocale() === $l)>{{ \App\Support\Locales::label($l) }}</option>@endforeach</select>
<div class="help">{{ __('settings.language_help') }}</div></div>
<div class="field"><label>{{ __('settings.timezone') }}</label>
<select name="timezone" dir="ltr">@foreach($timezones as $tz => $label)<option value="{{ $tz }}" @selected($user->preferredTimezone() === $tz)>{{ $label }}</option>@endforeach</select>
<div class="help">{{ __('settings.timezone_help') }}</div></div>
<h2 style="margin-top:18px">{{ __('settings.notifications') }}</h2>
@foreach(['notify_reminders_telegram','notify_support_email','notify_billing_email','weekly_summary_telegram','email_weekly_review','email_product_updates'] as $flag)
<label class="check"><input type="checkbox" name="{{ $flag }}" value="1" @checked($prefs[$flag])> {{ __('settings.'.$flag) }}</label>
@endforeach
<h2 style="margin-top:18px">{{ __('settings.ai') }}</h2>
<label class="check"><input type="checkbox" name="ai_enabled" value="1" @checked($prefs['ai_enabled'])> {{ __('settings.ai_enabled') }}</label>
<div class="field"><label>{{ __('settings.ai_response_language') }}</label>
<select name="ai_response_language"><option value="auto" @selected($prefs['ai_response_language'] === 'auto')>{{ __('settings.ai_language_auto') }}</option>@foreach(\App\Support\Locales::SUPPORTED as $l)<option value="{{ $l }}" @selected($prefs['ai_response_language'] === $l)>{{ \App\Support\Locales::label($l) }}</option>@endforeach</select></div>
<button class="btn primary">{{ __('settings.save_preferences') }}</button>
</form>

<form class="card" method="post" action="{{ route('account.planning') }}" id="planning">@csrf
<h2>{{ __('settings.planning') }}</h2>
<p class="help">{{ __('settings.planning_help') }}</p>
<div class="field"><label>{{ __('settings.work_days') }}</label>
<div class="row">@foreach(($user->preferredLocale() === 'fa' ? [6,7,1,2,3,4,5] : [1,2,3,4,5,6,7]) as $d)
<label class="check" style="margin:4px 0"><input type="checkbox" name="work_days[]" value="{{ $d }}" @checked(in_array($d, $prefs['work_days'], true))> {{ __('recurrence.weekdays')[$d - 1] }}</label>
@endforeach</div></div>
<div class="grid">
<div class="field"><label>{{ __('settings.work_start') }}</label><input type="time" name="work_start" value="{{ $prefs['work_start'] }}" dir="ltr" required></div>
<div class="field"><label>{{ __('settings.work_end') }}</label><input type="time" name="work_end" value="{{ $prefs['work_end'] }}" dir="ltr" required></div>
<div class="field"><label>{{ __('settings.default_task_minutes') }}</label><input type="number" name="default_task_minutes" min="5" max="480" value="{{ $prefs['default_task_minutes'] }}" required></div>
<div class="field"><label>{{ __('settings.break_minutes') }}</label><input type="number" name="break_minutes" min="0" max="120" value="{{ $prefs['break_minutes'] }}" required></div>
<div class="field"><label>{{ __('settings.planning_buffer_percent') }}</label><input type="number" name="planning_buffer_percent" min="0" max="60" value="{{ $prefs['planning_buffer_percent'] }}" required><div class="help">{{ __('settings.planning_buffer_help') }}</div></div>
</div>
<label class="check"><input type="checkbox" name="calendar_blocks_planning" value="1" @checked($prefs['calendar_blocks_planning'])> {{ __('settings.calendar_blocks_planning') }}</label>
<button class="btn primary">{{ __('settings.save_planning') }}</button>
</form>
</div>

<div>
<div class="card" id="calendar">
<h2>{{ __('calendar.title') }}</h2>
<p class="help">{{ __('calendar.help') }}</p>
@error('calendar')<div class="err">{{ $message }}</div>@enderror
@if(!$calendarAllowed)
<div class="err">{{ __('billing.limit.feature_calendar') }} <a href="{{ route('billing.index') }}">{{ __('billing.see_plans') }}</a></div>
@elseif($calendarConnection)
@php($cc = $calendarConnection)
<p><b>Google Calendar</b> · <span class="ltr">{{ $cc->account_email }}</span>
<span class="pill {{ $cc->status === 'active' ? 'g' : ($cc->status === 'revoked' ? 'r' : 'y') }}">{{ __('calendar.status.'.$cc->status) }}</span></p>
<p class="help">{{ __('calendar.last_sync') }}: {{ $cc->last_synced_at ? \App\Support\LocalDate::dateTime($cc->last_synced_at) : '—' }}
@if($cc->last_error && $cc->status !== 'active') · {{ $cc->status === 'revoked' ? __('calendar.revoked_help') : __('calendar.error_help') }}@endif</p>
@if($cc->status === 'revoked')
<a class="btn primary" href="{{ route('calendar.connect', 'google') }}">{{ __('calendar.reconnect') }}</a>
@else
<form method="post" action="{{ route('calendar.update', $cc) }}">@csrf
<div class="field"><label>{{ __('calendar.import_from') }}</label>
<select name="calendar_id">@forelse($calendarChoices as $cal)<option value="{{ $cal['id'] }}" @selected($cc->calendar_id === $cal['id'] || ($cc->calendar_id === 'primary' && $cal['primary']))>{{ $cal['name'] }}</option>@empty<option value="{{ $cc->calendar_id }}">{{ $cc->calendar_id === 'primary' ? __('calendar.primary') : $cc->calendar_id }}</option>@endforelse</select></div>
<label class="check"><input type="checkbox" name="import_enabled" value="1" @checked($cc->import_enabled)> {{ __('calendar.import_enabled') }}</label>
<div class="field"><label>{{ __('calendar.export_to') }}</label>
<select name="export_calendar_id">@forelse(array_filter($calendarChoices, fn ($c) => $c['writable']) as $cal)<option value="{{ $cal['id'] }}" @selected($cc->export_calendar_id === $cal['id'] || ($cc->export_calendar_id === 'primary' && $cal['primary']))>{{ $cal['name'] }}</option>@empty<option value="{{ $cc->export_calendar_id ?? 'primary' }}">{{ __('calendar.primary') }}</option>@endforelse</select></div>
<label class="check"><input type="checkbox" name="export_enabled" value="1" @checked($cc->export_enabled)> {{ __('calendar.export_enabled') }}</label>
<div class="help">{{ __('calendar.one_way_help') }}</div>
<div class="row" style="margin-top:10px"><button class="btn primary">{{ __('calendar.save') }}</button></div>
</form>
<div class="row" style="margin-top:10px">
<form method="post" action="{{ route('calendar.sync', $cc) }}">@csrf<button class="btn">↻ {{ __('calendar.sync_now') }}</button></form>
<form method="post" action="{{ route('calendar.disconnect', $cc) }}" onsubmit="return confirm(@js(__('calendar.disconnect_confirm')))">@csrf<button class="btn danger">{{ __('calendar.disconnect') }}</button></form>
</div>
@endif
@elseif($calendarConfigured)
<a class="btn primary" href="{{ route('calendar.connect', 'google') }}">{{ __('calendar.connect_google') }}</a>
@else
<p class="help">{{ __('calendar.not_configured') }}</p>
@endif
@if($calendarAllowed)
<h2 style="margin-top:18px">{{ __('calendar.feed_title') }}</h2>
<p class="help">{{ __('calendar.feed_help') }}</p>
@if(session('calendar_feed_url'))<div class="ok"><div>{{ __('calendar.feed_copy') }}</div><input readonly class="ltr" style="width:100%" value="{{ session('calendar_feed_url') }}" onclick="this.select()"></div>@endif
<div class="row">
<form method="post" action="{{ route('calendar.feed.create') }}">@csrf<button class="btn">{{ $calendarFeedActive ? __('calendar.feed_regenerate') : __('calendar.feed_create') }}</button></form>
@if($calendarFeedActive)<form method="post" action="{{ route('calendar.feed.delete') }}">@csrf<button class="btn danger">{{ __('calendar.feed_delete') }}</button></form>@endif
</div>
@endif
</div>

<div class="card">
<h2>{{ __('settings.telegram') }}</h2>
@if(!$telegramAllowed)<div class="err">{{ __('settings.telegram_not_in_plan') }} <a href="{{ route('billing.index') }}">{{ __('billing.see_plans') }}</a></div>@endif
@if($user->telegram_chat_id)
<p>{{ __('settings.telegram_connected') }} <b class="ltr">{{ $user->telegram_username ? '@'.$user->telegram_username : __('settings.telegram_no_username') }}</b></p>
<p class="muted">Chat ID: <span class="ltr">{{ $user->telegram_chat_id }}</span><br>{{ __('settings.telegram_connected_help') }}</p>
<form method="post" action="{{ route('account.telegram.unlink') }}">@csrf<button class="btn danger" type="submit">{{ __('settings.telegram_unlink') }}</button></form>
@else
<p class="muted">{{ __('settings.telegram_link_help') }}</p>
<form method="post" action="{{ route('account.telegram.link') }}">@csrf<button class="btn primary" type="submit">{{ __('settings.telegram_make_code') }}</button></form>
@if(session('telegram_link_code'))
<div class="code">{{ session('telegram_link_code') }}</div>
@if(config('services.telegram.bot_username'))
<p><a class="tg" href="https://t.me/{{ ltrim(config('services.telegram.bot_username'), '@') }}?start={{ session('telegram_link_code') }}" target="_blank" rel="noopener">{{ __('settings.telegram_one_click') }}</a></p>
<p class="muted">{{ __('settings.telegram_send', ['bot' => '@'.ltrim(config('services.telegram.bot_username'), '@')]) }} <b class="ltr">/start {{ session('telegram_link_code') }}</b></p>
@else
<p class="muted">{{ __('settings.telegram_send_plain') }} <b class="ltr">/start {{ session('telegram_link_code') }}</b></p>
@endif
@endif
@endif
</div>

<form class="card" method="post" action="{{ route('account.password') }}">@csrf
<h2>{{ __('settings.change_password') }}</h2>
<div class="field"><label>{{ __('settings.current_password') }}</label><input type="password" name="current_password" autocomplete="current-password" required></div>
<div class="field"><label>{{ __('settings.new_password') }}</label><input type="password" name="password" minlength="8" autocomplete="new-password" required><div class="help">{{ __('auth.password_help') }}</div></div>
<div class="field"><label>{{ __('settings.new_password_confirm') }}</label><input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required></div>
<button class="btn primary">{{ __('common.save') }}</button>
</form>

<div class="card">
<h2>{{ __('settings.privacy') }}</h2>
<dl>
<dt>{{ __('settings.account_info') }}</dt><dd>{{ $user->name }} — <span class="ltr">{{ $user->email }}</span></dd>
<dt>{{ __('settings.member_since') }}</dt><dd>{{ \App\Support\LocalDate::date($user->created_at) }}</dd>
<dt>{{ __('settings.plan') }}</dt><dd>{{ $plan?->localizedName() ?? '—' }} · <a href="{{ route('billing.index') }}">{{ __('app.nav.billing') }}</a></dd>
<dt>{{ __('settings.legal') }}</dt><dd><a href="{{ \App\Support\LocaleUrls::counterpart('marketing.privacy', app()->getLocale()) }}">{{ __('auth.privacy') }}</a> · <a href="{{ \App\Support\LocaleUrls::counterpart('marketing.terms', app()->getLocale()) }}">{{ __('auth.terms') }}</a></dd>
</dl>
<h2 style="margin-top:18px">{{ __('settings.export') }}</h2>
<p class="muted">{{ __('settings.export_help') }}</p>
<a class="btn" href="{{ route('account.export') }}">⬇ {{ __('settings.export_button') }}</a>
</div>

<form class="card danger-zone" method="post" action="{{ route('account.destroy') }}" onsubmit="return confirm(@js(__('settings.delete_button')) + '?')">@csrf
<h2>{{ __('settings.delete_account') }}</h2>
<p class="muted" style="line-height:1.9">{{ __('settings.delete_help') }}</p>
<div class="field"><label>{{ __('settings.current_password') }}</label><input type="password" name="current_password" autocomplete="current-password" required></div>
<div class="field"><label>{{ __('settings.delete_type', ['word' => __('settings.delete_confirm_word')]) }}</label><input name="confirm" autocomplete="off" required></div>
<button class="btn danger">{{ __('settings.delete_button') }}</button>
</form>
</div>
</div>
@endsection
