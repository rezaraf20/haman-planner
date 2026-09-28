@extends('layouts.page')
@section('title', __('admin.int_title'))
@section('content')
<h1>{{ __('admin.int_title') }}</h1>
<p class="help">{{ __('admin.int_help') }}</p>
<form class="card" method="post" action="{{ route('admin.integrations.save') }}" autocomplete="off">@csrf
<h2>Google Calendar</h2>
<p>@if($fields['google_client_id']['source'] !== 'none' && $fields['google_client_secret']['source'] !== 'none')<span class="pill g">{{ __('admin.int_ready') }}</span>@else<span class="pill y">{{ __('admin.int_missing') }}</span>@endif
<span class="muted">{{ __('admin.int_connections', ['active' => $connections['active'] ?? 0, 'error' => $connections['error'] ?? 0, 'revoked' => $connections['revoked'] ?? 0]) }}</span></p>
<ol class="help" style="line-height:2">@foreach(__('admin.int_google_steps') as $step)<li>{{ $step }}</li>@endforeach</ol>
<div class="field"><label>{{ __('admin.int_redirect_uri') }}</label><input readonly class="ltr" value="{{ $redirectUri }}" onclick="this.select()"><div class="help">{{ __('admin.int_redirect_help') }}</div></div>
<div class="field"><label>Client ID</label><input name="google_client_id" dir="ltr" value="" placeholder="{{ $fields['google_client_id']['masked'] ?? 'xxxxxxxx.apps.googleusercontent.com' }}"><div class="help">{{ __('admin.pay_secret_help') }} · {{ __('admin.pay_source.'.$fields['google_client_id']['source']) }}</div></div>
<div class="field"><label>Client secret</label><input type="password" name="google_client_secret" dir="ltr" value="" autocomplete="new-password" placeholder="{{ $fields['google_client_secret']['masked'] ?? 'GOCSPX-…' }}"><div class="help">{{ __('admin.pay_secret_help') }} · {{ __('admin.pay_source.'.$fields['google_client_secret']['source']) }}</div></div>
@if($fields['google_client_id']['source'] === 'panel')<label class="check"><input type="checkbox" name="clear_google" value="1"> {{ __('admin.pay_clear') }}</label>@endif
<button class="btn primary">{{ __('admin.int_save') }}</button>
</form>
@endsection
