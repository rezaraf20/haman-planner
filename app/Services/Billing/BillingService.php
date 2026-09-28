<?php
declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Analytics\ProductEvents;
use App\Services\Billing\Gateways\GatewayException;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Gateways\StripeGateway;
use App\Services\Billing\Gateways\ZarinpalGateway;
use App\Services\Telegram\TelegramService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Subscription lifecycle, independent of any single payment provider.
 *
 *  checkout → provider → callback → verify with provider → paid payment → subscription period
 *
 * Renewal is explicit (the customer pays for the next period before or after it ends);
 * paying for a different plan switches to it immediately with a fresh period.
 */
final class BillingService
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return array<string,PaymentGateway> every known driver, configured or not */
    public function gateways(): array
    {
        return ['zarinpal' => app(ZarinpalGateway::class), 'stripe' => app(StripeGateway::class)];
    }

    public function gateway(string $key): ?PaymentGateway
    {
        return $this->gateways()[$key] ?? null;
    }

    /** Configured gateway that charges in the currency used for this locale. */
    public function gatewayForLocale(string $locale): ?PaymentGateway
    {
        $currency = $this->currencyFor($locale);
        foreach ($this->gateways() as $g) {
            if ($g->isConfigured() && $g->currency() === $currency) {
                return $g;
            }
        }
        return null;
    }

    public function currencyFor(string $locale): string
    {
        return (string) (config('billing.locale_currency.'.$locale) ?? 'USD');
    }

    public static function addInterval(Carbon $from, string $interval): Carbon
    {
        return $interval === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();
    }

    // ----------------------------------------------------------------- checkout

    /** Creates a pending payment and returns the provider URL to redirect the customer to. */
    public function startCheckout(User $user, Plan $plan, string $interval, string $providerKey): string
    {
        $gateway = $this->gateway($providerKey);
        if (!$gateway || !$gateway->isConfigured()) {
            throw new BillingException('billing.errors.provider_unavailable');
        }
        if (!$plan->is_active || !in_array($interval, Plan::INTERVALS, true)) {
            throw new BillingException('billing.errors.plan_unavailable');
        }
        $amount = $plan->price($gateway->currency(), $interval);
        if ($amount === null || $amount <= 0) {
            throw new BillingException('billing.errors.plan_unavailable');
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'billing_interval' => $interval,
            'provider' => $gateway->key(),
            'amount' => $amount,
            'currency' => $gateway->currency(),
            'status' => 'pending',
            'customer_email' => $user->email,
            'meta' => ['locale' => app()->getLocale()],
        ]);

        $callback = URL::temporarySignedRoute('billing.callback', now()->addDay(), ['payment' => $payment->id]);
        $description = __('billing.checkout_description', [
            'plan' => $plan->localizedName(), 'interval' => __('billing.interval.'.$interval),
        ]);

        try {
            $session = $gateway->start($payment, $callback, $description);
        } catch (GatewayException|\Illuminate\Http\Client\ConnectionException $e) {
            report($e);
            $payment->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
            throw new BillingException('billing.errors.provider_error');
        }
        $payment->update(['provider_reference' => $session->reference]);
        ProductEvents::record($user, ProductEvents::CHECKOUT_STARTED, ['plan' => $plan->code, 'provider' => $gateway->key(), 'interval' => $interval]);
        return $session->redirectUrl;
    }

    /** Handles the provider redirect. Idempotent: a payment is activated at most once. */
    public function completeCheckout(Payment $payment, Request $request): Payment
    {
        if ($payment->status !== 'pending') {
            return $payment;
        }
        $gateway = $this->gateway((string) $payment->provider);
        if (!$gateway) {
            return $payment;
        }
        try {
            $result = $gateway->verify($payment, $request);
        } catch (\Throwable $e) {
            report($e);
            return $payment; // stays pending; the customer can retry the callback link
        }

        return DB::transaction(function () use ($payment, $result) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return $locked ?? $payment;
            }
            if (!$result->paid) {
                $locked->update([
                    'status' => $result->canceledByCustomer ? 'canceled' : 'failed',
                    'failure_reason' => $result->failureReason,
                ]);
                return $locked;
            }
            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'transaction_reference' => $result->transactionReference,
                'meta' => array_merge((array) $locked->meta, ['verification' => $result->raw]),
            ]);
            $this->activatePaid($locked);
            return $locked->fresh();
        });
    }

    private function activatePaid(Payment $payment): void
    {
        $user = User::query()->find($payment->user_id);
        $plan = Plan::query()->find($payment->plan_id);
        if (!$user || !$plan) {
            return;
        }
        $interval = (string) $payment->billing_interval;
        $current = $this->entitlements->currentSubscription($user);

        if ($current && $current->plan_id === $plan->id && $current->status !== 'trialing' && $current->billing_interval !== null) {
            // Renewal of the same plan: extend from the end of the current period.
            $start = $current->current_period_end && $current->current_period_end->isFuture() ? Carbon::instance($current->current_period_end) : now();
            $current->update([
                'status' => 'active',
                'billing_interval' => $interval,
                'current_period_end' => self::addInterval($start, $interval),
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'expiry_notified_at' => null,
                'provider' => $payment->provider,
            ]);
            $subscription = $current;
        } else {
            $previousPlan = $current?->plan;
            if ($current) {
                $current->update(['status' => 'expired', 'ended_at' => now(), 'current_period_end' => now()]);
            }
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_interval' => $interval,
                'current_period_start' => now(),
                'current_period_end' => self::addInterval(now(), $interval),
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
            ]);
            $isUpgrade = $previousPlan && !$previousPlan->isFree() && $plan->sort_order > $previousPlan->sort_order;
            ProductEvents::record($user, $isUpgrade ? ProductEvents::SUBSCRIPTION_UPGRADED : ProductEvents::SUBSCRIPTION_STARTED, [
                'plan' => $plan->code, 'interval' => $interval, 'provider' => $payment->provider,
            ]);
        }
        $payment->update(['subscription_id' => $subscription->id]);
        $this->entitlements->forget($user);
        $this->issueInvoice($payment, $user, $plan);
    }

    public function issueInvoice(Payment $payment, User $user, Plan $plan): Invoice
    {
        return Invoice::query()->firstOrCreate(['payment_id' => $payment->id], [
            'user_id' => $user->id,
            'number' => sprintf('%s-%s-%06d', config('billing.invoice_prefix', 'HP'), now()->format('Y'), $payment->id),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'billing_name' => $user->name,
            'billing_email' => $user->email,
            'lines' => [[
                'plan' => $plan->code,
                'description' => ['fa' => $plan->localizedName('fa'), 'en' => $plan->localizedName('en')],
                'interval' => $payment->billing_interval,
                'amount' => $payment->amount,
            ]],
            'issued_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------- lifecycle

    public function hadTrial(User $user): bool
    {
        return Subscription::query()->where('user_id', $user->id)->whereNotNull('trial_ends_at')->exists();
    }

    public function startTrial(User $user, Plan $plan): Subscription
    {
        if (!$plan->is_active || $plan->trial_days <= 0) {
            throw new BillingException('billing.errors.no_trial');
        }
        if ($this->hadTrial($user)) {
            throw new BillingException('billing.errors.trial_used');
        }
        $current = $this->entitlements->currentSubscription($user);
        if ($current && $current->status === 'active') {
            throw new BillingException('billing.errors.already_subscribed');
        }
        $end = now()->addDays($plan->trial_days);
        $sub = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'trialing',
            'trial_ends_at' => $end, 'current_period_start' => now(), 'current_period_end' => $end, 'provider' => 'trial',
        ]);
        $this->entitlements->forget($user);
        ProductEvents::record($user, ProductEvents::TRIAL_STARTED, ['plan' => $plan->code]);
        return $sub;
    }

    /** Cancels at the end of the paid (or trial) period — access continues until then. */
    public function cancel(User $user): Subscription
    {
        $sub = $this->entitlements->currentSubscription($user);
        if (!$sub || $sub->status === 'canceled') {
            throw new BillingException('billing.errors.nothing_to_cancel');
        }
        $sub->update(['status' => 'canceled', 'cancel_at_period_end' => true, 'canceled_at' => now()]);
        $this->entitlements->forget($user);
        ProductEvents::record($user, ProductEvents::SUBSCRIPTION_CANCELED, ['plan' => $sub->plan?->code]);
        return $sub;
    }

    public function resume(User $user): Subscription
    {
        $sub = $this->entitlements->currentSubscription($user);
        if (!$sub || $sub->status !== 'canceled') {
            throw new BillingException('billing.errors.nothing_to_resume');
        }
        $sub->update([
            'status' => $sub->trial_ends_at !== null && $sub->billing_interval === null ? 'trialing' : 'active',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ]);
        $this->entitlements->forget($user);
        return $sub;
    }

    /** Admin: grant a plan for N months without a payment (offline payment, partner, support). */
    public function grant(User $user, Plan $plan, int $months): Subscription
    {
        $current = $this->entitlements->currentSubscription($user);
        if ($current) {
            $current->update(['status' => 'expired', 'ended_at' => now(), 'current_period_end' => now()]);
        }
        $sub = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => null,
            'current_period_start' => now(), 'current_period_end' => now()->addMonths(max(1, $months)), 'provider' => 'manual',
        ]);
        $this->entitlements->forget($user);
        return $sub;
    }

    /** Scheduled: close ended periods and remind customers shortly before renewal is due. */
    public function processLifecycle(?TelegramService $telegram = null): array
    {
        $expired = Subscription::query()->whereIn('status', ['trialing', 'active', 'canceled'])
            ->whereNotNull('current_period_end')->where('current_period_end', '<=', now())
            ->update(['status' => 'expired', 'ended_at' => now()]);

        $reminded = 0;
        $days = (int) config('billing.renewal_reminder_days', 3);
        $due = Subscription::query()->with(['user', 'plan'])->whereIn('status', ['active', 'trialing'])
            ->whereNull('expiry_notified_at')->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now(), now()->addDays($days)])->limit(200)->get();
        foreach ($due as $sub) {
            $user = $sub->user;
            if ($user && $user->is_active) {
                $this->sendRenewalNotice($user, $sub, $telegram);
            }
            $sub->update(['expiry_notified_at' => now()]);
            $reminded++;
        }
        return ['expired' => $expired, 'reminded' => $reminded];
    }

    private function sendRenewalNotice(User $user, Subscription $sub, ?TelegramService $telegram): void
    {
        $locale = $user->preferredLocale();
        $text = __('billing.renewal_notice', [
            'plan' => $sub->plan?->localizedName($locale) ?? '',
            'date' => \App\Support\LocalDate::date($sub->current_period_end, $locale, $user->preferredTimezone()),
            'url' => route('billing.index'),
        ], $locale);
        if ($telegram && $user->telegram_chat_id) {
            try { $telegram->sendMessage($user->telegram_chat_id, $text); } catch (\Throwable $e) { report($e); }
        }
        if ($user->preference('notify_billing_email')) {
            try {
                Mail::raw($text, fn ($m) => $m->to($user->email, $user->name)->subject(__('billing.renewal_subject', [], $locale)));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function describePrice(Plan $plan, string $currency, string $interval): ?string
    {
        $amount = $plan->price($currency, $interval);
        return $amount === null ? null : Money::format($amount, $currency);
    }
}
