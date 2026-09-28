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
use App\Services\Billing\Gateways\RecurringGateway;
use App\Services\Billing\Gateways\StripeGateway;
use App\Services\Billing\Gateways\ZarinpalGateway;
use App\Services\Telegram\TelegramService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Subscription lifecycle, independent of any single payment provider.
 *
 *  checkout → provider → callback → verify with provider → paid payment → subscription period
 *
 * Renewal is explicit (the customer pays for the next period before or after it ends);
 * paying for a different plan switches to it immediately with a fresh period.
 *
 * Gateways that implement RecurringGateway (Stripe with a webhook secret) instead create a
 * provider-managed subscription: renewals, failed charges and cancellations then arrive as
 * signed webhooks (StripeWebhookHandler) and are applied here.
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
            'meta' => ['locale' => app()->getLocale(), 'mode' => $this->recurringFor($gateway) ? 'subscription' : 'one_time'],
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

    /** Whether checkouts with this gateway create an auto-renewing provider subscription. */
    public function recurringFor(PaymentGateway $gateway): bool
    {
        return $gateway instanceof RecurringGateway && $gateway->supportsRecurring();
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
        $verification = (array) ($payment->meta['verification'] ?? []);
        $providerSubscription = is_string($verification['subscription'] ?? null) ? $verification['subscription'] : null;

        if ($providerSubscription !== null) {
            // Provider-managed subscription: always a fresh local subscription tied to the provider ID.
            $previousPlan = $current?->plan;
            if ($current) {
                $this->endReplacedSubscription($current);
            }
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_interval' => $interval,
                'current_period_start' => now(),
                'current_period_end' => self::addInterval(now(), $interval),
                'provider' => $payment->provider,
                'provider_reference' => $providerSubscription,
                'provider_customer_id' => is_string($verification['customer'] ?? null) ? $verification['customer'] : null,
                'auto_renew' => true,
            ]);
            $isUpgrade = $previousPlan && !$previousPlan->isFree() && $plan->sort_order > $previousPlan->sort_order;
            ProductEvents::record($user, $isUpgrade ? ProductEvents::SUBSCRIPTION_UPGRADED : ProductEvents::SUBSCRIPTION_STARTED, [
                'plan' => $plan->code, 'interval' => $interval, 'provider' => $payment->provider, 'auto_renew' => true,
            ]);
        } elseif ($current && $current->plan_id === $plan->id && $current->status !== 'trialing' && $current->billing_interval !== null && !$current->auto_renew) {
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
                $this->endReplacedSubscription($current);
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
        $this->notify($user, 'subscription_started', 'sub-start-'.$subscription->id, ['plan' => $plan->localizedName($user->preferredLocale())]);
    }

    /** Close the subscription being replaced; an auto-renewing one is also stopped at the provider. */
    private function endReplacedSubscription(Subscription $current): void
    {
        if ($current->auto_renew && $current->provider_reference) {
            $gateway = $this->gateway((string) $current->provider);
            if ($gateway instanceof RecurringGateway) {
                try {
                    $gateway->cancelNow($current);
                } catch (\Throwable $e) {
                    report($e); // the local record still ends; the webhook for the deletion is harmless
                }
            }
        }
        $current->update(['status' => 'expired', 'ended_at' => now(), 'current_period_end' => now(), 'auto_renew' => false]);
    }

    // ----------------------------------------------------------------- provider webhooks

    /**
     * A renewal charge succeeded (e.g. Stripe invoice.paid for a billing cycle). Idempotent per
     * provider invoice: records a paid Payment + Invoice once and extends the period.
     */
    public function recordRenewal(Subscription $sub, string $invoiceReference, int $amount, string $currency, ?Carbon $periodEnd): ?Payment
    {
        return DB::transaction(function () use ($sub, $invoiceReference, $amount, $currency, $periodEnd) {
            $locked = Subscription::query()->whereKey($sub->id)->lockForUpdate()->first();
            if (!$locked || Payment::query()->where('provider', $locked->provider)->where('provider_reference', $invoiceReference)->exists()) {
                return null;
            }
            $user = User::query()->find($locked->user_id);
            $plan = Plan::query()->find($locked->plan_id);
            if (!$user || !$plan) {
                return null;
            }
            $base = $locked->current_period_end && $locked->current_period_end->isFuture() ? Carbon::instance($locked->current_period_end) : now();
            $end = $periodEnd && $periodEnd->isFuture() ? $periodEnd : self::addInterval($base, (string) ($locked->billing_interval ?: 'monthly'));
            $payment = Payment::create([
                'user_id' => $user->id, 'subscription_id' => $locked->id, 'plan_id' => $plan->id,
                'billing_interval' => $locked->billing_interval, 'provider' => $locked->provider,
                'provider_reference' => $invoiceReference, 'transaction_reference' => $invoiceReference,
                'amount' => $amount, 'currency' => strtoupper($currency), 'status' => 'paid', 'paid_at' => now(),
                'customer_email' => $user->email, 'meta' => ['mode' => 'subscription', 'renewal' => true],
            ]);
            $locked->update([
                'status' => $locked->cancel_at_period_end ? 'canceled' : 'active',
                'current_period_start' => $base->lt(now()) ? now() : $base,
                'current_period_end' => $end, 'past_due_at' => null, 'expiry_notified_at' => null, 'ended_at' => null,
            ]);
            $this->entitlements->forget($user);
            $this->issueInvoice($payment, $user, $plan);
            return $payment;
        });
    }

    /** A renewal charge failed: keep access during the grace window and tell the customer once per invoice. */
    public function markPastDue(Subscription $sub, string $invoiceReference): void
    {
        $first = $sub->status !== 'past_due';
        $sub->update(['status' => 'past_due', 'past_due_at' => $sub->past_due_at ?? now()]);
        $user = $sub->user;
        if (!$user) {
            return;
        }
        $this->entitlements->forget($user);
        if ($first) {
            ProductEvents::record($user, ProductEvents::PAYMENT_FAILED, ['plan' => $sub->plan?->code, 'provider' => $sub->provider]);
        }
        $this->notify($user, 'payment_failed', 'pay-failed-'.$invoiceReference, [
            'plan' => (string) $sub->plan?->localizedName($user->preferredLocale()),
            'days' => Subscription::pastDueGraceDays(),
        ]);
    }

    /**
     * Mirror the provider's view of a subscription (status, renewal flag, period end).
     *
     * @param string $providerStatus provider status mapped to: active | past_due | canceled
     */
    public function syncProviderState(Subscription $sub, string $providerStatus, bool $cancelAtPeriodEnd, ?Carbon $periodEnd): void
    {
        $attrs = ['cancel_at_period_end' => $cancelAtPeriodEnd];
        if ($periodEnd) {
            $attrs['current_period_end'] = $periodEnd;
        }
        $wasCanceled = $sub->status === 'canceled';
        if ($providerStatus === 'canceled') {
            $this->endProviderSubscription($sub);
            return;
        }
        if ($providerStatus === 'past_due') {
            $attrs['status'] = 'past_due';
            $attrs['past_due_at'] = $sub->past_due_at ?? now();
        } elseif ($cancelAtPeriodEnd) {
            $attrs['status'] = 'canceled';
            $attrs['canceled_at'] = $sub->canceled_at ?? now();
        } else {
            $attrs += ['status' => 'active', 'canceled_at' => null, 'past_due_at' => null];
        }
        $sub->update($attrs);
        if ($sub->user) {
            $this->entitlements->forget($sub->user);
            if (!$wasCanceled && $sub->status === 'canceled') {
                ProductEvents::record($sub->user, ProductEvents::SUBSCRIPTION_CANCELED, ['plan' => $sub->plan?->code, 'source' => 'provider']);
                $this->notify($sub->user, 'subscription_canceled', 'sub-cancel-'.$sub->id.'-'.$sub->canceled_at?->timestamp, $this->cancelParams($sub));
            }
        }
    }

    /** The provider ended the subscription (period over after cancellation, or unpaid): access stops. */
    public function endProviderSubscription(Subscription $sub): void
    {
        if ($sub->status === 'expired') {
            return;
        }
        $end = $sub->current_period_end && $sub->current_period_end->lt(now()) ? $sub->current_period_end : now();
        $sub->update(['status' => 'expired', 'ended_at' => now(), 'current_period_end' => $end, 'auto_renew' => false]);
        if ($sub->user) {
            $this->entitlements->forget($sub->user);
        }
    }

    private function cancelParams(Subscription $sub): array
    {
        $user = $sub->user;
        return [
            'plan' => (string) $sub->plan?->localizedName($user?->preferredLocale()),
            'date' => $sub->current_period_end ? \App\Support\LocalDate::date($sub->current_period_end, $user?->preferredLocale() ?? 'fa', $user?->preferredTimezone()) : '',
        ];
    }

    /** Send a lifecycle email (deduplicated); never throws. */
    private function notify(User $user, string $type, string $dedupe, array $params = []): void
    {
        try {
            app(\App\Services\Notifications\LifecycleMailer::class)->send($user, $type, $params, $dedupe);
        } catch (\Throwable $e) {
            report($e);
        }
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
        $this->notify($user, 'trial_started', 'trial-start-'.$sub->id, [
            'plan' => $plan->localizedName($user->preferredLocale()),
            'date' => \App\Support\LocalDate::date($end, $user->preferredLocale(), $user->preferredTimezone()),
        ]);
        return $sub;
    }

    /** Cancels at the end of the paid (or trial) period — access continues until then. */
    public function cancel(User $user): Subscription
    {
        $sub = $this->entitlements->currentSubscription($user);
        if (!$sub || $sub->status === 'canceled') {
            throw new BillingException('billing.errors.nothing_to_cancel');
        }
        $this->tellProvider($sub, true);
        $sub->update(['status' => 'canceled', 'cancel_at_period_end' => true, 'canceled_at' => now()]);
        $this->entitlements->forget($user);
        ProductEvents::record($user, ProductEvents::SUBSCRIPTION_CANCELED, ['plan' => $sub->plan?->code]);
        $this->notify($user, 'subscription_canceled', 'sub-cancel-'.$sub->id.'-'.$sub->canceled_at?->timestamp, $this->cancelParams($sub));
        return $sub;
    }

    public function resume(User $user): Subscription
    {
        $sub = $this->entitlements->currentSubscription($user);
        if (!$sub || $sub->status !== 'canceled') {
            throw new BillingException('billing.errors.nothing_to_resume');
        }
        $this->tellProvider($sub, false);
        $sub->update([
            'status' => $sub->trial_ends_at !== null && $sub->billing_interval === null ? 'trialing' : 'active',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ]);
        $this->entitlements->forget($user);
        return $sub;
    }

    /** For provider-managed subscriptions, the provider must agree before the local state changes. */
    private function tellProvider(Subscription $sub, bool $cancel): void
    {
        if (!$sub->auto_renew || !$sub->provider_reference) {
            return;
        }
        $gateway = $this->gateway((string) $sub->provider);
        if (!$gateway instanceof RecurringGateway || !$gateway->isConfigured()) {
            throw new BillingException('billing.errors.provider_unavailable');
        }
        try {
            $gateway->setCancelAtPeriodEnd($sub, $cancel);
        } catch (\Throwable $e) {
            report($e);
            throw new BillingException('billing.errors.provider_error');
        }
    }

    /** Admin: grant a plan for N months without a payment (offline payment, partner, support). */
    public function grant(User $user, Plan $plan, int $months): Subscription
    {
        $current = $this->entitlements->currentSubscription($user);
        if ($current) {
            $this->endReplacedSubscription($current);
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
        // Manually renewed periods end on time. Provider-managed (auto-renewing) ones get a short
        // grace for a late renewal webhook, and past_due ones the configured payment-retry grace.
        $expired = Subscription::query()->whereIn('status', Subscription::LIVE)->where('auto_renew', false)
            ->whereNotNull('current_period_end')->where('current_period_end', '<=', now())
            ->update(['status' => 'expired', 'ended_at' => now()]);
        $expired += Subscription::query()->whereIn('status', Subscription::LIVE)->where('auto_renew', true)
            ->whereNotNull('current_period_end')->where('current_period_end', '<=', now()->subDays(max(0, (int) config('billing.webhook_grace_days', 3))))
            ->update(['status' => 'expired', 'ended_at' => now()]);
        $expired += Subscription::query()->where('status', 'past_due')
            ->whereNotNull('current_period_end')->where('current_period_end', '<=', now()->subDays(Subscription::pastDueGraceDays()))
            ->update(['status' => 'expired', 'ended_at' => now()]);

        $reminded = 0;
        $days = (int) config('billing.renewal_reminder_days', 3);
        // Auto-renewing subscriptions renew by themselves — no "please renew" reminder.
        $due = Subscription::query()->with(['user', 'plan'])->whereIn('status', ['active', 'trialing'])->where('auto_renew', false)
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
        // Preference (notify_billing_email) and dedupe are handled by the mailer.
        $this->notify($user, $sub->status === 'trialing' ? 'trial_ending' : 'renewal_reminder', 'renew-'.$sub->id.'-'.$sub->current_period_end?->timestamp, [
            'plan' => $sub->plan?->localizedName($locale) ?? '',
            'date' => \App\Support\LocalDate::date($sub->current_period_end, $locale, $user->preferredTimezone()),
            'url' => route('billing.index'),
        ]);
    }

    public function describePrice(Plan $plan, string $currency, string $interval): ?string
    {
        $amount = $plan->price($currency, $interval);
        return $amount === null ? null : Money::format($amount, $currency);
    }
}
