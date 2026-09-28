@extends('layouts.page')
@section('title', __('admin.pay_title'))
@section('content')
<h1>{{ __('admin.pay_title') }}</h1>
<p class="help">{{ __('admin.pay_help') }}</p>
@unless ($appUrlIsHttps)
<div class="err">{{ __('admin.pay_https_warning', ['url' => config('app.url')]) }}</div>
@endunless

<div class="card tbl"><table>
@foreach ($gateways as $key => $g)
<tr><td><b>{{ __('admin.pay_provider.'.$key) }}</b></td><td class="ltr">{{ $g->currency() }}</td>
<td>@if ($g->isConfigured())<span class="pill g">{{ __('admin.pay_active') }}</span>@else<span class="pill y">{{ __('admin.pay_inactive') }}</span>@endif</td></tr>
@endforeach
</table></div>

<form method="post" action="{{ route('admin.payment-settings.save') }}" autocomplete="off">@csrf
<div class="card">
<h2>{{ __('admin.pay_provider.zarinpal') }}</h2>
<label class="check"><input type="checkbox" name="zarinpal_enabled" value="1" @checked($fields['zarinpal_enabled']['value'])> {{ __('admin.pay_enable') }}</label>
<div class="field"><label>{{ __('admin.pay_merchant') }}</label>
<input type="text" name="zarinpal_merchant_id" dir="ltr" value="" placeholder="{{ $fields['zarinpal_merchant_id']['masked'] ?? 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' }}" maxlength="64">
<div class="help">{{ __('admin.pay_secret_help') }} · {{ __('admin.pay_source.'.$fields['zarinpal_merchant_id']['source']) }}</div>
@if ($fields['zarinpal_merchant_id']['source'] === 'panel')<label class="check"><input type="checkbox" name="clear_zarinpal_merchant_id" value="1"> {{ __('admin.pay_clear') }}</label>@endif
</div>
<label class="check"><input type="checkbox" name="zarinpal_sandbox" value="1" @checked($fields['zarinpal_sandbox']['value'])> {{ __('admin.pay_sandbox') }}</label>
<div class="help">{{ __('admin.pay_zarinpal_help') }}</div>
</div>

<div class="card">
<h2>{{ __('admin.pay_provider.stripe') }}</h2>
<label class="check"><input type="checkbox" name="stripe_enabled" value="1" @checked($fields['stripe_enabled']['value'])> {{ __('admin.pay_enable') }}</label>
<div class="field"><label>{{ __('admin.pay_stripe_secret') }}</label>
<input type="password" name="stripe_secret" dir="ltr" value="" placeholder="{{ $fields['stripe_secret']['masked'] ?? 'sk_test_…' }}" maxlength="255" autocomplete="new-password">
<div class="help">{{ __('admin.pay_secret_help') }} · {{ __('admin.pay_source.'.$fields['stripe_secret']['source']) }}</div>
@if ($fields['stripe_secret']['source'] === 'panel')<label class="check"><input type="checkbox" name="clear_stripe_secret" value="1"> {{ __('admin.pay_clear') }}</label>@endif
</div>
<div class="help">{{ __('admin.pay_stripe_help') }}</div>
<div class="field"><label>{{ __('admin.pay_webhook_secret') }}</label>
<input type="password" name="stripe_webhook_secret" dir="ltr" value="" placeholder="{{ $fields['stripe_webhook_secret']['masked'] ?? 'whsec_…' }}" maxlength="255" autocomplete="new-password">
<div class="help">{{ __('admin.pay_secret_help') }} · {{ __('admin.pay_source.'.$fields['stripe_webhook_secret']['source']) }}</div>
@if ($fields['stripe_webhook_secret']['source'] === 'panel')<label class="check"><input type="checkbox" name="clear_stripe_webhook_secret" value="1"> {{ __('admin.pay_clear') }}</label>@endif
</div>
<div class="help">{{ __('admin.pay_webhook_help') }}</div>
<p class="help">{{ __('admin.pay_webhook_url') }} <span class="ltr">{{ $stripeWebhookUrl }}</span></p>
<p class="help">{{ __('admin.pay_webhook_events') }} <span class="ltr">{{ implode(', ', $stripeWebhookEvents) }}</span></p>
<p class="help">{{ __('admin.pay_mode') }}: <b>{{ $fields['stripe_webhook_secret']['value'] ? __('admin.pay_mode_recurring') : __('admin.pay_mode_one_time') }}</b>
@if ($lastWebhook) · {{ __('admin.pay_last_webhook') }}: <span class="ltr">{{ $lastWebhook->type }}</span> ({{ __('admin.webhook_status.'.$lastWebhook->status) }}, {{ \App\Support\LocalDate::dateTime($lastWebhook->created_at) }})@endif</p>
</div>

<div class="card">
<p class="help">{{ __('admin.pay_callback_help') }} <span class="ltr">{{ $callbackBase }}</span></p>
<p class="help">{{ __('admin.pay_prices_help') }} <a href="{{ route('admin.plans') }}">{{ __('admin.nav.plans') }}</a></p>
</div>
<button class="btn primary">{{ __('admin.pay_save') }}</button>
</form>
@endsection
