<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use App\Support\LocalDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly Entitlements $entitlements) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        // Only the payment methods that charge in this language's currency (fa → toman: Zibal/Zarinpal; en → USD: Stripe).
        $currency = $this->billing->currencyFor(app()->getLocale());
        $gateways = array_filter($this->billing->gateways(), fn ($g) => $g->isConfigured() && $g->currency() === $currency);
        return view('billing.index', [
            'summary' => $this->entitlements->summary($user),
            'plans' => Plan::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get(),
            'currency' => $currency,
            'interval' => in_array($request->query('interval'), Plan::INTERVALS, true) ? $request->query('interval') : 'monthly',
            'gateways' => $gateways,
            'hadTrial' => $this->billing->hadTrial($user),
            'payments' => Payment::query()->with(['plan', 'invoice'])->where('user_id', $user->id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'integer'],
            'interval' => ['required', Rule::in(Plan::INTERVALS)],
            'provider' => ['required', 'string', Rule::in(array_keys($this->billing->gateways()))],
        ]);
        $plan = Plan::query()->where('is_active', true)->findOrFail($data['plan']);
        try {
            return redirect()->away($this->billing->startCheckout($request->user(), $plan, $data['interval'], $data['provider']));
        } catch (BillingException $e) {
            return back()->withErrors(['billing' => __($e->getMessage())]);
        }
    }

    /** Provider redirect target (signed URL). Works even if the browser session was lost. */
    public function callback(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['Authority', 'Status', 'session_id', 'canceled']), 403);
        return $this->finish($request, $this->billing->completeCheckout($payment, $request));
    }

    /**
     * Zibal return URL. Zibal appends its own query string, so this URL cannot carry a signature:
     * the payment is looked up by Zibal's trackId and is activated only after server-side verification.
     */
    public function zibalReturn(Request $request): RedirectResponse
    {
        $trackId = (string) $request->query('trackId', '');
        $payment = ctype_digit($trackId) && strlen($trackId) <= 20
            ? Payment::query()->where('provider', 'zibal')->where('provider_reference', $trackId)->first()
            : null;
        if (!$payment) {
            return redirect()->route($request->user() ? 'billing.index' : 'login')->with('billing_error', __('billing.callback_failed'));
        }
        return $this->finish($request, $this->billing->completeCheckout($payment, $request));
    }

    private function finish(Request $request, Payment $payment): RedirectResponse
    {
        $locale = (string) ($payment->meta['locale'] ?? app()->getLocale());
        app()->setLocale(in_array($locale, ['fa', 'en'], true) ? $locale : app()->getLocale());

        $message = match ($payment->status) {
            'paid' => __('billing.callback_paid', ['plan' => $payment->plan?->localizedName() ?? '']),
            'canceled' => __('billing.callback_canceled'),
            'failed' => __('billing.callback_failed'),
            default => __('billing.callback_pending'),
        };
        $target = $request->user() && $request->user()->id === $payment->user_id ? route('billing.index') : route('login');
        return redirect()->to($target)->with($payment->status === 'failed' ? 'billing_error' : 'status', $message);
    }

    public function trial(Request $request): RedirectResponse
    {
        $plan = Plan::query()->where('is_active', true)->findOrFail((int) $request->input('plan'));
        try {
            $this->billing->startTrial($request->user(), $plan);
        } catch (BillingException $e) {
            return back()->withErrors(['billing' => __($e->getMessage())]);
        }
        return redirect()->route('billing.index')->with('status', __('billing.trial_started'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        try {
            $sub = $this->billing->cancel($request->user());
        } catch (BillingException $e) {
            return back()->withErrors(['billing' => __($e->getMessage())]);
        }
        return redirect()->route('billing.index')->with('status', __('billing.canceled_notice', ['date' => LocalDate::date($sub->current_period_end)]));
    }

    public function resume(Request $request): RedirectResponse
    {
        try {
            $this->billing->resume($request->user());
        } catch (BillingException $e) {
            return back()->withErrors(['billing' => __($e->getMessage())]);
        }
        return redirect()->route('billing.index')->with('status', __('billing.resumed_notice'));
    }

    public function invoice(Request $request, Invoice $invoice): View
    {
        abort_unless($invoice->user_id === $request->user()->id || $request->user()->is_admin, 404);
        return view('billing.invoice', ['invoice' => $invoice->load('payment')]);
    }
}
