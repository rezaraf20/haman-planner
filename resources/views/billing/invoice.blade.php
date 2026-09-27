@php($brand = \App\Support\AppSettings::all())
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locales::dir() }}">
<head>
@include('partials.fonts')<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{{ __('billing.invoice_title', ['number' => $invoice->number]) }}</title>
<style>body{font-family:var(--font);color:#172033;margin:0;background:#f4f6f9}.sheet{max-width:760px;margin:30px auto;background:#fff;border:1px solid #e4e8ef;border-radius:14px;padding:34px}
h1{margin:0 0 4px;font-size:22px}.muted{color:#64748b}table{width:100%;border-collapse:collapse;margin-top:22px}th,td{padding:10px;border-bottom:1px solid #e4e8ef;text-align:start}th{font-size:12px;color:#64748b}
.total{font-weight:900;font-size:18px}.head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}.ltr{direction:ltr;unicode-bidi:isolate}
.btn{border:1px solid #d5dbe4;background:#fff;border-radius:9px;padding:8px 13px;cursor:pointer;font:inherit}@media print{.noprint{display:none}body{background:#fff}.sheet{border:0;margin:0}}</style></head>
<body><div class="sheet">
<div class="head">
<div><h1>{{ $brand['app_name'] }}</h1><div class="muted">{{ __('billing.invoice_title', ['number' => $invoice->number]) }}</div></div>
<div><div class="muted">{{ __('billing.invoice_issued') }}</div><b>{{ \App\Support\LocalDate::date($invoice->issued_at) }}</b></div>
</div>
<p style="margin-top:22px"><span class="muted">{{ __('billing.invoice_to') }}:</span> {{ $invoice->billing_name }} <span class="ltr">{{ $invoice->billing_email }}</span></p>
<table>
<tr><th>{{ __('billing.plan') }}</th><th>{{ __('billing.amount') }}</th></tr>
@foreach ((array) $invoice->lines as $line)
<tr><td>{{ $line['description'][app()->getLocale()] ?? ($line['plan'] ?? '') }} — {{ __('billing.interval.'.($line['interval'] ?? 'monthly')) }}</td><td>{{ \App\Support\Money::format((int) ($line['amount'] ?? 0), $invoice->currency) }}</td></tr>
@endforeach
<tr><td class="total">{{ __('billing.invoice_total') }}</td><td class="total">{{ \App\Support\Money::format((int) $invoice->amount, $invoice->currency) }}</td></tr>
</table>
<p class="muted">{{ __('billing.invoice_paid', ['provider' => __('billing.provider.'.($invoice->payment?->provider ?? 'manual'))]) }} @if($invoice->payment?->transaction_reference) · {{ __('billing.reference') }}: <span class="ltr">{{ $invoice->payment->transaction_reference }}</span>@endif</p>
<p class="noprint"><button class="btn" onclick="window.print()">{{ __('billing.print') }}</button> <a href="{{ route('billing.index') }}">{{ __('common.back') }}</a></p>
</div></body></html>
