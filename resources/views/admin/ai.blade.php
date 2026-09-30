@extends('layouts.page')
@section('title', __('admin.ai.title'))
@section('content')
@php($n = fn ($v) => \App\Support\LocalDate::number((int) $v))
@php($f = $edit)
<h1>{{ __('admin.ai.title') }}</h1>
<p class="help" style="margin-top:-8px">{{ __('admin.ai.intro') }}</p>

<div class="grid" style="margin-bottom:16px">
<div class="stat"><span>{{ __('admin.ai.tokens_month') }}</span><b>{{ $n($totals->t) }}</b><span>{{ __('admin.ai.in_out', ['in' => $n($totals->p), 'out' => $n($totals->c)]) }}</span></div>
<div class="stat"><span>{{ __('admin.ai.requests_month') }}</span><b>{{ $n($totals->n) }}</b></div>
<div class="stat"><span>{{ __('admin.ai.failures_month') }}</span><b @if($totals->f > 0) style="color:var(--danger)" @endif>{{ $n($totals->f) }}</b></div>
<div class="stat"><span>{{ __('admin.ai.cost_month') }}</span><b>{{ $cost === null ? '—' : '$'.number_format($cost, 2) }}</b>@if($cost === null)<span>{{ __('admin.ai.cost_hint') }}</span>@endif</div>
</div>

<div class="card tbl">
<div class="head"><h2>{{ __('admin.ai.connections') }}</h2><span class="muted small">{{ __('admin.ai.order_hint') }}</span></div>
<table>
<tr><th>{{ __('admin.ai.col_name') }}</th><th>{{ __('admin.ai.col_status') }}</th><th>{{ __('admin.ai.col_usage') }}</th><th>{{ __('admin.ai.col_balance') }}</th><th>{{ __('admin.ai.col_health') }}</th><th></th></tr>
@forelse ($providers as $p)
@php($u = $byProvider[(string) $p->id] ?? null)
@php($used = (int) ($u->t ?? 0))
@php($pct = $p->monthly_token_budget ? min(100, (int) round($used / max(1, $p->monthly_token_budget) * 100)) : null)
<tr>
<td><b>{{ $p->name }}</b> <span class="muted small">#{{ $n($p->priority) }}</span><div class="muted small ltr">{{ $p->driver }} · {{ $p->model }}</div><div class="muted small ltr">{{ $p->maskedKey() }}</div></td>
<td>@if(!$p->is_active)<span class="pill">{{ __('admin.ai.inactive') }}</span>@elseif($p->overBudget())<span class="pill r">{{ __('admin.ai.over_budget') }}</span>@else<span class="pill g">{{ __('admin.ai.active') }}</span>@endif</td>
<td style="min-width:180px"><div class="num">{{ $n($used) }} @if($p->monthly_token_budget) / {{ $n($p->monthly_token_budget) }}@endif</div>
@if($pct !== null)<div class="meter"><i class="{{ $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '') }}" style="width:{{ $pct }}%"></i></div><div class="muted small">{{ __('admin.ai.remaining', ['n' => $n($p->remainingBudget())]) }}</div>@else<div class="muted small">{{ __('admin.ai.no_budget') }}</div>@endif
@if($u)<div class="muted small">{{ __('admin.ai.in_out', ['in' => $n($u->p), 'out' => $n($u->c)]) }} · {{ __('admin.ai.calls', ['n' => $n($u->n)]) }}@if($c = $p->cost((int) $u->p, (int) $u->c)) · ${{ number_format($c, 2) }}@endif</div>@endif</td>
<td>@if($p->supportsBalance())@if($p->balance)<b class="num ltr">{{ number_format((float) ($p->balance['amount'] ?? 0), 2) }} {{ $p->balance['currency'] ?? '' }}</b><div class="muted small">{{ \App\Support\LocalDate::dateTime($p->balance_checked_at) }}</div>@endif
<form method="post" action="{{ route('admin.ai.balance', $p) }}">@csrf<button class="btn sm">{{ __('admin.ai.refresh_balance') }}</button></form>@else<span class="muted small">{{ __('admin.ai.balance_unsupported') }}</span>@endif</td>
<td class="small">@if($p->last_success_at)<div>{{ __('admin.ai.last_ok') }}: {{ \App\Support\LocalDate::dateTime($p->last_success_at) }}</div>@endif
@if($p->last_error && (!$p->last_success_at || $p->last_error_at?->gt($p->last_success_at)))<div style="color:var(--danger)" class="ltr">{{ \Illuminate\Support\Str::limit(\App\Http\Controllers\Admin\AdminBusinessController::maskSecrets($p->last_error), 90) }}</div>@endif</td>
<td><div class="actions">
<form method="post" action="{{ route('admin.ai.test', $p) }}">@csrf<button class="btn sm">{{ __('admin.ai.test') }}</button></form>
<a class="btn sm" href="{{ route('admin.ai', ['edit' => $p->id]) }}#form">{{ __('admin.ai.edit') }}</a>
<form method="post" action="{{ route('admin.ai.toggle', $p) }}">@csrf<button class="btn sm">{{ $p->is_active ? __('admin.ai.disable') : __('admin.ai.enable') }}</button></form>
<form method="post" action="{{ route('admin.ai.delete', $p) }}" onsubmit="return confirm(@js(__('admin.ai.delete_confirm')))">@csrf<button class="btn sm danger">{{ __('admin.ai.delete') }}</button></form>
</div></td>
</tr>
@empty
<tr><td colspan="6" class="muted">{{ $envConfigured ? __('admin.ai.using_env', ['label' => $envLabel]) : __('admin.ai.none') }}</td></tr>
@endforelse
@if($providers->isNotEmpty() && $envConfigured)
<tr><td colspan="6" class="muted small">{{ __('admin.ai.env_note', ['label' => $envLabel]) }}</td></tr>
@endif
</table>
</div>

<form class="card" id="form" method="post" action="{{ $f ? route('admin.ai.update', $f) : route('admin.ai.store') }}" autocomplete="off">@csrf
<div class="head"><h2>{{ $f ? __('admin.ai.edit_title', ['name' => $f->name]) : __('admin.ai.add_title') }}</h2>@if($f)<a class="btn sm" href="{{ route('admin.ai') }}">{{ __('common.cancel') }}</a>@endif</div>
<div class="formgrid">
<div class="field"><label>{{ __('admin.ai.f_name') }}</label><input name="name" value="{{ old('name', $f?->name) }}" maxlength="80" required placeholder="OpenRouter — main"></div>
<div class="field"><label>{{ __('admin.ai.f_driver') }}</label><select name="driver" id="ai-driver" dir="ltr">@foreach($drivers as $d => $url)<option value="{{ $d }}" data-url="{{ $url }}" @selected(old('driver', $f?->driver) === $d)>{{ $d }}</option>@endforeach</select></div>
<div class="field"><label>{{ __('admin.ai.f_model') }}</label><input name="model" dir="ltr" value="{{ old('model', $f?->model) }}" maxlength="120" required placeholder="gpt-4o-mini / gemini-2.5-flash / deepseek-chat"></div>
<div class="field"><label>{{ __('admin.ai.f_key') }}</label><input type="password" name="api_key" dir="ltr" maxlength="500" autocomplete="new-password" placeholder="{{ $f ? $f->maskedKey() : 'sk-…' }}" {{ $f ? '' : 'required' }}><div class="help">{{ $f ? __('admin.ai.key_keep') : __('admin.ai.key_help') }}</div></div>
<div class="field full"><label>{{ __('admin.ai.f_base') }}</label><input name="base_url" id="ai-base" dir="ltr" value="{{ old('base_url', $f?->base_url) }}" maxlength="255"><div class="help" id="ai-base-help">{{ __('admin.ai.base_help') }}</div></div>
<div class="field"><label>{{ __('admin.ai.f_priority') }}</label><input type="number" name="priority" min="0" max="1000" value="{{ old('priority', $f?->priority ?? 10) }}"><div class="help">{{ __('admin.ai.priority_help') }}</div></div>
<div class="field"><label>{{ __('admin.ai.f_budget') }}</label><input type="number" name="monthly_token_budget" min="0" value="{{ old('monthly_token_budget', $f?->monthly_token_budget) }}" placeholder="2000000"><div class="help">{{ __('admin.ai.budget_help') }}</div></div>
<div class="field"><label>{{ __('admin.ai.f_price_in') }}</label><input type="number" step="0.0001" min="0" name="input_price_per_million" value="{{ old('input_price_per_million', $f?->input_price_per_million) }}" placeholder="0.15"></div>
<div class="field"><label>{{ __('admin.ai.f_price_out') }}</label><input type="number" step="0.0001" min="0" name="output_price_per_million" value="{{ old('output_price_per_million', $f?->output_price_per_million) }}" placeholder="0.60"><div class="help">{{ __('admin.ai.price_help') }}</div></div>
</div>
<label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $f?->is_active ?? true))> {{ __('admin.ai.f_active') }}</label>
<button class="btn primary">{{ $f ? __('admin.ai.save') : __('admin.ai.add') }}</button>
</form>
<script>
(function(){var d=document.getElementById('ai-driver'),h=document.getElementById('ai-base-help'),b=document.getElementById('ai-base');function u(){var url=d.options[d.selectedIndex].dataset.url;b.placeholder=url||'https://…/v1';b.required=!url;}d.addEventListener('change',u);u();})();
</script>

<div class="card">
<h2>{{ __('admin.ai.daily') }}</h2>
@php($max = max(1, max($days)))
<div class="bars">@foreach ($days as $day => $t)<div style="height:{{ max(3, (int) round($t / $max * 100)) }}%" title="{{ $day }}: {{ $t }}"><span>{{ $t ? \App\Support\LocalDate::number($t >= 1000 ? (int) round($t / 1000) : $t).($t >= 1000 ? 'k' : '') : '' }}</span></div>@endforeach</div>
<div class="row muted" style="justify-content:space-between;font-size:11px;margin-top:6px"><span>{{ \App\Support\LocalDate::date(array_key_first($days)) }}</span><span>{{ \App\Support\LocalDate::date(array_key_last($days)) }}</span></div>
</div>

<div class="grid2">
<div class="card tbl"><h2>{{ __('admin.ai.by_feature') }}</h2><table>
<tr><th>{{ __('admin.ai.col_feature') }}</th><th>{{ __('admin.ai.col_tokens') }}</th><th>{{ __('admin.ai.col_calls') }}</th></tr>
@forelse($features as $x)<tr><td>{{ __('admin.ai.feature.'.($x->feature ?: 'chat')) }}</td><td class="num">{{ $n($x->t) }}</td><td class="num">{{ $n($x->n) }}</td></tr>@empty<tr><td colspan="3" class="muted">{{ __('admin.ai.no_usage') }}</td></tr>@endforelse
</table></div>
<div class="card tbl"><h2>{{ __('admin.ai.top_users') }}</h2><table>
<tr><th>{{ __('settings.name') }}</th><th>{{ __('admin.ai.col_tokens') }}</th><th>{{ __('admin.ai.col_calls') }}</th></tr>
@forelse($topUsers as $x)<tr><td>{{ $names[$x->user_id] ?? '#'.$x->user_id }}</td><td class="num">{{ $n($x->t) }}</td><td class="num">{{ $n($x->n) }}</td></tr>@empty<tr><td colspan="3" class="muted">{{ __('admin.ai.no_usage') }}</td></tr>@endforelse
</table></div>
</div>

@if($recentErrors->isNotEmpty())
<div class="card tbl"><h2>{{ __('admin.ai.recent_errors') }}</h2><table>
@foreach($recentErrors as $e)<tr><td class="small">{{ \App\Support\LocalDate::dateTime($e->created_at) }}</td><td>{{ $e->provider }} <span class="muted small ltr">{{ $e->model }}</span></td><td class="ltr small" style="color:var(--danger)">{{ \App\Http\Controllers\Admin\AdminBusinessController::maskSecrets((string) $e->error) }}</td></tr>@endforeach
</table></div>
@endif
<p class="help">{{ __('admin.ai.privacy_note') }}</p>
@endsection
