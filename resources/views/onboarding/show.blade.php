@extends('auth.layout')
@section('title', __('onboarding.title'))
@section('subtitle', __('onboarding.step_of', ['step' => $step, 'total' => \App\Http\Controllers\OnboardingController::STEPS]))
@section('content')
<div class="progress" style="margin:-8px 0 20px" role="progressbar" aria-valuenow="{{ $step }}" aria-valuemin="1" aria-valuemax="{{ \App\Http\Controllers\OnboardingController::STEPS }}"><i style="width:{{ (int) round($step / \App\Http\Controllers\OnboardingController::STEPS * 100) }}%"></i></div>
@if ($step === 1)
<form method="post" action="{{ route('onboarding.store', $step) }}">@csrf
<h2 style="margin:0 0 6px">{{ __('onboarding.welcome_title', ['name' => $user->name]) }}</h2>
<p class="muted" style="line-height:1.9">{{ __('onboarding.welcome_text') }}</p>
<div class="field"><label>{{ __('onboarding.name') }}</label><input name="name" value="{{ old('name', $user->name) }}" maxlength="120" required></div>
<div class="field"><label>{{ __('onboarding.language') }}</label><select name="locale">@foreach(\App\Support\Locales::SUPPORTED as $l)<option value="{{ $l }}" @selected($user->preferredLocale() === $l)>{{ \App\Support\Locales::label($l) }}</option>@endforeach</select></div>
<div class="field"><label>{{ __('onboarding.timezone') }}</label><select name="timezone" dir="ltr">@foreach($timezones as $tz => $label)<option value="{{ $tz }}" @selected($user->preferredTimezone() === $tz)>{{ $label }}</option>@endforeach</select></div>
<button class="btn">{{ __('onboarding.next') }}</button>
</form>
@elseif ($step === 2)
<form method="post" action="{{ route('onboarding.store', $step) }}">@csrf
<h2 style="margin:0 0 6px">{{ __('onboarding.goal_title') }}</h2>
<p class="muted" style="line-height:1.9">{{ __('onboarding.goal_text') }}</p>
<div class="field"><label>{{ __('onboarding.goal_label') }}</label><input name="goal" value="{{ old('goal') }}" maxlength="255" placeholder="{{ __('onboarding.goal_placeholder') }}"></div>
<div class="field"><label>{{ __('onboarding.task_label') }}</label><input name="task" value="{{ old('task') }}" maxlength="255" placeholder="{{ __('onboarding.task_placeholder') }}"></div>
<button class="btn">{{ __('onboarding.next') }}</button>
</form>
<div class="foot"><a href="{{ route('onboarding', ['step' => 3]) }}">{{ __('onboarding.skip_step') }}</a></div>
@elseif ($step === 3)
<h2 style="margin:0 0 6px">{{ __('onboarding.telegram_title') }}</h2>
<p class="muted" style="line-height:1.9">{{ __('onboarding.telegram_text') }}</p>
@if ($user->telegram_chat_id)
<div class="ok">{{ __('settings.telegram_connected') }} {{ $user->telegram_username ? '@'.$user->telegram_username : '✓' }}</div>
@elseif (session('telegram_link_code'))
<div style="font-size:24px;letter-spacing:5px;font-weight:700;background:var(--surface-3);padding:14px;border-radius:var(--r-lg);text-align:center;margin:14px 0;direction:ltr">{{ session('telegram_link_code') }}</div>
@if (config('services.telegram.bot_username'))<p><a href="https://t.me/{{ ltrim(config('services.telegram.bot_username'), '@') }}?start={{ session('telegram_link_code') }}" target="_blank" rel="noopener">{{ __('settings.telegram_one_click') }}</a></p>@endif
<p class="muted">{{ __('settings.telegram_send_plain') }} <b dir="ltr">/start {{ session('telegram_link_code') }}</b></p>
@endif
@if (!$user->telegram_chat_id && !session('telegram_link_code'))
<form method="post" action="{{ route('account.telegram.link') }}" style="margin-bottom:10px">@csrf<button class="btn" style="background:#229ED9">{{ __('onboarding.telegram_button') }}</button></form>
@endif
<form method="post" action="{{ route('onboarding.store', $step) }}">@csrf<button class="btn">{{ __('onboarding.next') }}</button></form>
@else
<form method="post" action="{{ route('onboarding.store', $step) }}">@csrf
<h2 style="margin:0 0 6px">{{ __('onboarding.ai_title') }}</h2>
<p class="muted" style="line-height:1.9">{{ __('onboarding.ai_text') }}</p>
<div class="ok">{{ $aiLimit === null ? __('onboarding.ai_unlimited') : __('onboarding.ai_allowance', ['limit' => \App\Support\LocalDate::number($aiLimit)]) }}</div>
<button class="btn">{{ __('onboarding.finish') }}</button>
</form>
@endif
<form method="post" action="{{ route('onboarding.skip') }}" class="foot">@csrf<button class="btn ghost" style="width:auto;min-height:0;text-decoration:underline">{{ __('onboarding.skip_all') }}</button></form>
@endsection
