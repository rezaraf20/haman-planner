@extends('layouts.page')
@section('title', __('admin.settings_title'))
@section('content')
<h1>{{ __('admin.settings_title') }}</h1>
<form method="post" action="{{ route('admin.settings.save') }}" enctype="multipart/form-data">
@csrf
<div class="card">
<h2>{{ __('admin.brand') }}</h2>
<div class="field"><label>{{ __('admin.app_name') }}</label><input type="text" name="app_name" value="{{ old('app_name', $s['app_name']) }}" maxlength="60" required></div>
<div class="field"><label>{{ __('admin.tagline') }} (فارسی)</label><input type="text" name="app_tagline" dir="rtl" value="{{ old('app_tagline', $s['app_tagline']) }}" maxlength="120" placeholder="{{ __('auth.default_tagline', [], 'fa') }}"></div>
<div class="field"><label>{{ __('admin.tagline') }} (English)</label><input type="text" name="app_tagline_en" dir="ltr" value="{{ old('app_tagline_en', $s['app_tagline_en']) }}" maxlength="120" placeholder="{{ __('auth.default_tagline', [], 'en') }}"><div class="help">{{ __('admin.tagline_help') }}</div></div>
<div class="field"><label>{{ __('admin.logo') }}</label>
@if($s['logo'])<div class="row" style="margin-bottom:8px"><img src="{{ $s['logo'] }}" alt="" style="height:56px;max-width:200px;object-fit:contain;border:1px solid var(--line);border-radius:10px;padding:4px;background:#fff"><label class="check"><input type="checkbox" name="remove_logo" value="1"> {{ __('admin.remove_logo') }}</label></div>@endif
<input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
<div class="help">{{ __('admin.logo_help') }}</div></div>
</div>
<div class="card">
<h2>{{ __('admin.fonts_title') }}</h2>
<div class="grid">
<div class="field"><label>{{ __('admin.font_fa') }}</label>
<select name="font_fa">
<option value="vazirmatn" @selected($s['font_fa'] !== 'custom')>{{ __('admin.font_vazirmatn') }}</option>
<option value="custom" @selected($s['font_fa'] === 'custom')>{{ __('admin.font_custom') }}{{ $s['font_fa_name'] ? ' — '.$s['font_fa_name'] : '' }}</option>
</select></div>
<div class="field"><label>{{ __('admin.font_en') }}</label>
<select name="font_en">
<option value="poppins" @selected($s['font_en'] !== 'persian')>Poppins</option>
<option value="persian" @selected($s['font_en'] === 'persian')>{{ __('admin.font_en_persian') }}</option>
</select></div>
</div>
<div class="field"><label>{{ __('admin.font_name') }}</label><input type="text" name="font_fa_name" value="{{ old('font_fa_name', $s['font_fa_name']) }}" maxlength="60" placeholder="IRANSans"></div>
<div class="grid">
@foreach (['regular' => 'font_regular', 'bold' => 'font_bold'] as $weight => $input)
<div class="field"><label>{{ __('admin.'.$input) }}</label>
<input type="file" name="{{ $input }}" accept=".woff2,.woff,font/woff2,font/woff">
@if (\App\Support\Fonts::hasCustom() && \App\Support\Fonts::customFile($weight))<label class="check"><input type="checkbox" name="remove_{{ $input }}" value="1"> {{ __('admin.font_remove') }}</label>@endif
</div>
@endforeach
</div>
<div class="help">{{ __('admin.fonts_help') }}</div>
</div>
<div class="card">
<h2>{{ __('admin.registration_support') }}</h2>
<label class="check"><input type="checkbox" name="registration_enabled" value="1" @checked($s['registration_enabled'])> {{ __('admin.registration_enabled') }}</label>
<label class="check"><input type="checkbox" name="signup_trial" value="1" @checked($s['signup_trial'])> {{ __('admin.signup_trial') }}</label>
<div class="help">{{ __('admin.signup_trial_help') }}</div>
<label class="check"><input type="checkbox" name="support_enabled" value="1" @checked($s['support_enabled'])> {{ __('admin.support_enabled') }}</label>
<div class="field"><label>{{ __('admin.support_note') }}</label><textarea name="support_note" maxlength="2000" placeholder="{{ __('admin.support_note_placeholder') }}">{{ old('support_note', $s['support_note']) }}</textarea><div class="help">{{ __('admin.support_note_help') }}</div></div>
</div>
<div class="card">
<h2>{{ __('admin.announcement_title') }}</h2>
<div class="field"><label>{{ __('admin.announcement') }}</label><textarea name="announcement" maxlength="500" placeholder="{{ __('admin.announcement_placeholder') }}">{{ old('announcement', $s['announcement']) }}</textarea><div class="help">{{ __('admin.announcement_help') }}</div></div>
</div>
<button class="btn primary">{{ __('admin.save_settings') }}</button>
</form>
@endsection
