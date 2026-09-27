@extends('layouts.page')
@section('title', __('admin.plans_title'))
@section('content')
<div class="row" style="justify-content:space-between"><h1>{{ __('admin.plans_title') }}</h1><a class="btn" href="{{ route('admin.plans', ['plan' => 'new']) }}">＋ {{ __('admin.new_plan') }}</a></div>
<div class="card tbl"><table>
<tr><th>{{ __('admin.plan_code') }}</th><th>{{ __('admin.col.plan') }}</th>@foreach (array_keys((array) config('billing.currencies')) as $cur)<th>{{ $cur }}</th>@endforeach<th>{{ __('billing.metric.ai_requests') }}</th><th>{{ __('admin.col.status') }}</th><th></th></tr>
@foreach ($plans as $p)
<tr><td class="ltr">{{ $p->code }}@if($p->is_default) <span class="pill b">default</span>@endif</td><td>{{ $p->localizedName() }}</td>
@foreach (array_keys((array) config('billing.currencies')) as $cur)<td>@foreach (\App\Models\Plan::INTERVALS as $iv)@if(($pr = $p->price($cur, $iv)) !== null){{ \App\Support\Money::format($pr, $cur) }} <span class="muted">/ {{ __('billing.interval.'.$iv) }}</span><br>@endif @endforeach</td>@endforeach
<td>{{ $p->limit('ai_requests') === null ? __('common.unlimited') : \App\Support\LocalDate::number($p->limit('ai_requests')) }}</td>
<td>{!! $p->is_active ? '<span class="pill g">'.e(__('common.active')).'</span>' : '<span class="pill r">'.e(__('common.inactive')).'</span>' !!}</td>
<td><a class="btn sm" href="{{ route('admin.plans', ['plan' => $p->id]) }}">{{ __('common.edit') }}</a></td></tr>
@endforeach
</table></div>
@if ($edit)
<form class="card" method="post" action="{{ route('admin.plans.save') }}">@csrf
<input type="hidden" name="id" value="{{ $edit->id }}">
<h2>{{ $edit->exists ? $edit->localizedName() : __('admin.new_plan') }}</h2>
<p class="help">{{ __('admin.plans_help') }}</p>
<div class="grid">
<div class="field"><label>{{ __('admin.plan_code') }}</label><input name="code" value="{{ old('code', $edit->code) }}" dir="ltr" required></div>
<div class="field"><label>{{ __('admin.plan_name_fa') }}</label><input name="name_fa" value="{{ old('name_fa', $edit->name['fa'] ?? '') }}" required></div>
<div class="field"><label>{{ __('admin.plan_name_en') }}</label><input name="name_en" value="{{ old('name_en', $edit->name['en'] ?? '') }}" dir="ltr" required></div>
<div class="field"><label>{{ __('admin.trial_days') }}</label><input name="trial_days" type="number" min="0" value="{{ old('trial_days', $edit->trial_days ?? 0) }}"></div>
<div class="field"><label>{{ __('admin.sort_order') }}</label><input name="sort_order" type="number" min="0" value="{{ old('sort_order', $edit->sort_order ?? 0) }}"></div>
</div>
<div class="field"><label>{{ __('admin.plan_desc_fa') }}</label><input name="desc_fa" value="{{ old('desc_fa', $edit->description['fa'] ?? '') }}"></div>
<div class="field"><label>{{ __('admin.plan_desc_en') }}</label><input name="desc_en" value="{{ old('desc_en', $edit->description['en'] ?? '') }}" dir="ltr"></div>
<div class="grid">
@foreach (array_keys((array) config('billing.currencies')) as $cur)@foreach (\App\Models\Plan::INTERVALS as $iv)
<div class="field"><label>{{ __('admin.price', ['currency' => $cur, 'interval' => __('billing.interval.'.$iv)]) }}</label><input name="price_{{ $cur }}_{{ $iv }}" type="number" min="0" value="{{ old('price_'.$cur.'_'.$iv, $edit->price($cur, $iv)) }}"></div>
@endforeach @endforeach
@foreach (array_keys((array) config('billing.metrics')) as $m)
<div class="field"><label>{{ __('admin.limit', ['metric' => __('billing.metric.'.$m)]) }}</label><input name="limit_{{ $m }}" type="number" min="0" value="{{ old('limit_'.$m, $edit->limit($m)) }}" placeholder="{{ __('common.unlimited') }}"></div>
@endforeach
</div>
@foreach ((array) config('billing.features') as $f)
<label class="check"><input type="checkbox" name="feature_{{ $f }}" value="1" @checked($edit->hasFeature($f))> {{ __('billing.feature.'.$f) }}</label>
@endforeach
<label class="check"><input type="checkbox" name="is_default" value="1" @checked($edit->is_default)> {{ __('admin.is_default') }}</label>
<label class="check"><input type="checkbox" name="is_active" value="1" @checked($edit->is_active)> {{ __('admin.is_active') }}</label>
<label class="check"><input type="checkbox" name="is_public" value="1" @checked($edit->is_public)> {{ __('admin.is_public') }}</label>
<button class="btn primary">{{ __('admin.save_plan') }}</button>
</form>
@endif
@endsection
