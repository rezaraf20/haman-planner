{{-- Plan cards. Vars: $plans, $currency, $interval, optional: $currentPlanId, $actions (bool), $gateways, $hadTrial, $summary --}}
@php($actions = $actions ?? false)
<div class="plans">
@foreach ($plans as $p)
@php($price = $p->price($currency, $interval))
@php($isCurrent = isset($currentPlanId) && $currentPlanId === $p->id)
<div class="plan {{ $isCurrent ? 'current' : '' }}">
<div class="plan-name">{{ $p->localizedName() }} @if($isCurrent)<span class="pill b">{{ __('billing.your_plan') }}</span>@endif</div>
<div class="plan-desc">{{ $p->localizedDescription() }}</div>
<div class="plan-price">
@if ($p->isFree()){{ __('billing.free') }}
@elseif ($price === null)<span class="muted" style="font-size:14px">{{ __('billing.not_sold') }}</span>
@else{{ \App\Support\Money::format($price, $currency) }} <small>{{ __('billing.per_interval.'.$interval) }}</small>@endif
</div>
<ul class="plan-features">
@foreach (array_keys((array) config('billing.metrics')) as $m)
@php($lim = $p->limit($m))
@if ($lim !== null || $m === 'ai_requests' || $plans->contains(fn ($o) => $o->limit($m) !== null))<li>{{ __('billing.metric.'.$m) }}: <b>{{ $lim === null ? __('common.unlimited') : \App\Support\LocalDate::number($lim) }}</b>{{ $m === 'ai_requests' ? ' / '.__('billing.per_interval.monthly') : '' }}</li>@endif
@endforeach
@foreach ((array) config('billing.features') as $f)
<li class="{{ $p->hasFeature($f) ? '' : 'no' }}">{{ $p->hasFeature($f) ? '✓' : '—' }} {{ __('billing.feature.'.$f) }}</li>
@endforeach
</ul>
@if ($actions && !$p->isFree())
<div class="plan-actions">
@if ($p->trial_days > 0 && !$hadTrial && !($summary['subscription'] && $summary['subscription']->status === 'active'))
<form method="post" action="{{ route('billing.trial') }}">@csrf<input type="hidden" name="plan" value="{{ $p->id }}"><button class="btn" style="width:100%">{{ __('billing.start_trial', ['days' => \App\Support\LocalDate::number($p->trial_days)]) }}</button></form>
<div class="help">{{ __('billing.trial_note') }}</div>
@endif
@php($autoRenewing = $isCurrent && ($summary['subscription'] ?? null)?->auto_renew && in_array($summary['subscription']->status, ['active', 'canceled'], true))
@if ($autoRenewing)
<div class="help">{{ __('billing.auto_renew_badge') }}</div>
@else
@php($payable = collect($gateways)->filter(fn ($g) => $p->price($g->currency(), $interval)))
@if ($payable->isNotEmpty())
<div class="pay-caption">{{ $isCurrent ? __('billing.renew') : __('billing.switch') }}</div>
@endif
@forelse ($payable as $g)
<form method="post" action="{{ route('billing.checkout') }}">@csrf
<input type="hidden" name="plan" value="{{ $p->id }}"><input type="hidden" name="interval" value="{{ $interval }}"><input type="hidden" name="provider" value="{{ $g->key() }}">
<button class="btn {{ $loop->first ? 'primary' : '' }} pay-btn" style="width:100%" aria-label="{{ ($isCurrent ? __('billing.renew') : __('billing.switch')).' — '.__('billing.pay_with', ['provider' => __('billing.provider_short.'.$g->key())]) }}"><svg class="i sm"><use href="#i-card"/></svg>{{ __('billing.pay_with', ['provider' => __('billing.provider_short.'.$g->key())]) }}</button>
</form>
@empty
<div class="help">{{ __('billing.payments_unavailable') }}</div>
@endforelse
@endif
</div>
@endif
</div>
@endforeach
</div>
