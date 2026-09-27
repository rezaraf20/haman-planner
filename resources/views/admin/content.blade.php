@extends('layouts.page')
@section('title', __('admin.content_title'))
@section('content')
@php($C = \App\Support\LandingContent::class)
@php($dir = $lang === 'fa' ? 'rtl' : 'ltr')
<div class="row" style="justify-content:space-between">
<h1>{{ __('admin.content_title') }}</h1>
<div class="row">
<a class="btn sm" href="{{ url($lang === 'fa' ? '/?preview=1' : '/en?preview=1') }}" target="_blank" rel="noopener">{{ __('admin.content_view_page') }} ↗</a>
</div>
</div>
<p class="help">{{ __('admin.content_help') }}</p>
<div class="sub">
@foreach (\App\Support\Locales::SUPPORTED as $l)
<a class="btn sm {{ $l === $lang ? 'on' : '' }}" href="{{ route('admin.content', ['lang' => $l]) }}">{{ \App\Support\Locales::label($l) }}</a>
@endforeach
</div>

<form method="post" action="{{ route('admin.content.save') }}">@csrf
<input type="hidden" name="lang" value="{{ $lang }}">
@foreach ($sections as $section => $fields)
<details class="card" @if($loop->first) open @endif>
<summary style="cursor:pointer;font-weight:800;font-size:16px">{{ __('admin.content_sections.'.$section) }}
@if (collect(array_keys($fields))->contains(fn ($f) => $C::isCustom($lang, $f)))<span class="pill b">{{ __('admin.content_custom') }}</span>@endif
</summary>
@foreach ($fields as $field => $type)
@php($name = str_replace('.', '__', $field))
@php($value = $C::value($lang, $field))
@php($label = __('admin.content_labels.'.explode('.', $field, 2)[1]))
<div class="field">
<label>{{ $label }} @if($C::isCustom($lang, $field))<span class="pill b">{{ __('admin.content_custom') }}</span>@endif</label>
@if ($type === 'text')
<input type="text" name="{{ $name }}" dir="{{ $dir }}" value="{{ $value }}" maxlength="400">
@elseif ($type === 'textarea')
<textarea name="{{ $name }}" dir="{{ $dir }}" rows="3" maxlength="4000">{{ $value }}</textarea>
@elseif ($type === 'lines')
<textarea name="{{ $name }}" dir="{{ $dir }}" rows="4">{{ implode("\n", (array) $value) }}</textarea>
<div class="help">{{ __('admin.content_lines_help') }}</div>
@else
@php($cols = (int) substr($type, 5))
@php($rows = array_values((array) $value))
@foreach (array_merge($rows, array_fill(0, 2, [])) as $i => $row)
<div class="row" style="align-items:flex-start;margin-bottom:8px;padding-bottom:8px;border-bottom:1px dashed var(--line)">
@if ($cols === 3)
<input type="text" name="{{ $name }}[{{ $i }}][0]" value="{{ $row[0] ?? '' }}" placeholder="{{ __('admin.content_icon') }}" style="width:70px;text-align:center" maxlength="8">
<input type="text" name="{{ $name }}[{{ $i }}][1]" dir="{{ $dir }}" value="{{ $row[1] ?? '' }}" placeholder="{{ __('admin.content_row_title') }}" style="flex:1;min-width:160px">
<textarea name="{{ $name }}[{{ $i }}][2]" dir="{{ $dir }}" rows="2" placeholder="{{ __('admin.content_row_text') }}" style="flex:2;min-width:220px">{{ $row[2] ?? '' }}</textarea>
@else
<input type="text" name="{{ $name }}[{{ $i }}][0]" dir="{{ $dir }}" value="{{ $row[0] ?? '' }}" placeholder="{{ __($section === 'faq' ? 'admin.content_question' : 'admin.content_row_title') }}" style="flex:1;min-width:200px">
<textarea name="{{ $name }}[{{ $i }}][1]" dir="{{ $dir }}" rows="3" placeholder="{{ __($section === 'faq' ? 'admin.content_answer' : 'admin.content_row_text') }}" style="flex:2;min-width:240px">{{ $row[1] ?? '' }}</textarea>
@endif
</div>
@endforeach
<div class="help">{{ __('admin.content_rows_help') }}</div>
@endif
</div>
@endforeach
@if ($section === 'terms')
<label class="check"><input type="checkbox" name="hide_legal_notice" value="1" @checked($hideLegalNotice)> {{ __('admin.content_hide_notice') }}</label>
<div class="help">{{ __('admin.content_hide_notice_help') }}</div>
@endif
</details>
@endforeach
<button class="btn primary">{{ __('admin.content_save') }}</button>
</form>

<form method="post" action="{{ route('admin.content.reset') }}" style="margin-top:14px" onsubmit="return confirm(@js(__('admin.content_reset_confirm')))">@csrf
<input type="hidden" name="lang" value="{{ $lang }}">
<button class="btn sm danger">{{ __('admin.content_reset') }}</button>
</form>
@endsection
