<?php
declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies verified Stripe events to local billing state. Each event ID is processed at most once
 * (webhook_events); a failed event can be retried by Stripe. Only the IDs/amounts needed are read
 * from the payload, and the payload itself is never stored or logged.
 *
 * Handled: checkout.session.completed, invoice.paid / invoice.payment_succeeded (renewals),
 * invoice.payment_failed, customer.subscription.updated, customer.subscription.deleted.
 */
final class StripeWebhookHandler
{
    public function __construct(private readonly BillingService $billing) {}

    /** @return string processed | ignored | duplicate | failed */
    public function handle(array $event): string
    {
        $eventId = mb_substr((string) $event['id'], 0, 255);
        $type = mb_substr((string) $event['type'], 0, 100);
        DB::table('webhook_events')->insertOrIgnore([
            'provider' => 'stripe', 'event_id' => $eventId, 'type' => $type, 'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            return DB::transaction(function () use ($eventId, $event, $type) {
                $row = WebhookEvent::query()->where('provider', 'stripe')->where('event_id', $eventId)->lockForUpdate()->first();
                if ($row === null || in_array($row->status, ['processed', 'ignored'], true)) {
                    return 'duplicate';
                }
                $result = $this->dispatch($type, (array) ($event['data']['object'] ?? []));
                $row->update(['status' => $result, 'error' => null, 'processed_at' => now()]);
                return $result;
            });
        } catch (\Throwable $e) {
            WebhookEvent::query()->where('provider', 'stripe')->where('event_id', $eventId)
                ->update(['status' => 'failed', 'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 500), 'updated_at' => now()]);
            Log::error('Stripe webhook processing failed', ['event_id' => $eventId, 'type' => $type, 'error' => $e->getMessage()]);
            return 'failed';
        }
    }

    /** @return string processed | ignored */
    private function dispatch(string $type, array $o): string
    {
        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->checkoutCompleted($o),
            'invoice.paid', 'invoice.payment_succeeded' => $this->invoicePaid($o),
            'invoice.payment_failed' => $this->invoiceFailed($o),
            'customer.subscription.updated' => $this->subscriptionUpdated($o),
            'customer.subscription.deleted' => $this->subscriptionDeleted($o),
            default => 'ignored',
        };
    }

    /** Same path as the browser redirect: re-verified with Stripe, activated at most once. */
    private function checkoutCompleted(array $session): string
    {
        $paymentId = (int) ($session['metadata']['payment_id'] ?? $session['client_reference_id'] ?? 0);
        $sessionId = (string) ($session['id'] ?? '');
        $payment = $paymentId > 0 ? Payment::query()->where('provider', 'stripe')->find($paymentId) : null;
        if (!$payment || $payment->status !== 'pending' || $sessionId === '' || $payment->provider_reference !== $sessionId) {
            return 'ignored';
        }
        $result = $this->billing->completeCheckout($payment, Request::create('/', 'GET', ['session_id' => $sessionId]));
        if ($result->status === 'pending') {
            throw new \RuntimeException('Checkout could not be verified yet'); // Stripe retries later
        }
        return 'processed';
    }

    private function invoicePaid(array $invoice): string
    {
        // The first invoice of a subscription is the checkout itself (activated above).
        if (($invoice['billing_reason'] ?? null) === 'subscription_create') {
            return 'ignored';
        }
        $sub = $this->subscriptionFor(self::invoiceSubscriptionId($invoice));
        $invoiceId = (string) ($invoice['id'] ?? '');
        if (!$sub || $invoiceId === '' || (int) ($invoice['amount_paid'] ?? 0) <= 0) {
            return 'ignored';
        }
        $this->billing->recordRenewal($sub, $invoiceId, (int) $invoice['amount_paid'], (string) ($invoice['currency'] ?? 'usd'), self::invoicePeriodEnd($invoice));
        return 'processed';
    }

    private function invoiceFailed(array $invoice): string
    {
        $sub = $this->subscriptionFor(self::invoiceSubscriptionId($invoice));
        if (!$sub || $sub->status === 'expired') {
            return 'ignored';
        }
        $this->billing->markPastDue($sub, (string) ($invoice['id'] ?? 'unknown'));
        return 'processed';
    }

    private function subscriptionUpdated(array $s): string
    {
        $sub = $this->subscriptionFor(is_string($s['id'] ?? null) ? $s['id'] : null);
        if (!$sub || $sub->status === 'expired') {
            return 'ignored';
        }
        $state = match ((string) ($s['status'] ?? '')) {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            default => null,
        };
        if ($state === null) {
            return 'ignored';
        }
        $end = $s['current_period_end'] ?? ($s['items']['data'][0]['current_period_end'] ?? null);
        $this->billing->syncProviderState($sub, $state, (bool) ($s['cancel_at_period_end'] ?? false), is_numeric($end) ? self::time((int) $end) : null);
        return 'processed';
    }

    private function subscriptionDeleted(array $s): string
    {
        $sub = $this->subscriptionFor(is_string($s['id'] ?? null) ? $s['id'] : null);
        if (!$sub) {
            return 'ignored';
        }
        $this->billing->endProviderSubscription($sub);
        return 'processed';
    }

    private function subscriptionFor(?string $providerId): ?Subscription
    {
        if ($providerId === null || $providerId === '') {
            return null;
        }
        return Subscription::query()->with(['user', 'plan'])->where('provider', 'stripe')->where('provider_reference', $providerId)->latest('id')->first();
    }

    /** Older API versions put the subscription on the invoice; newer ones under parent.subscription_details. */
    private static function invoiceSubscriptionId(array $invoice): ?string
    {
        return \App\Services\Billing\Gateways\StripeGateway::id($invoice['subscription'] ?? null)
            ?? \App\Services\Billing\Gateways\StripeGateway::id($invoice['parent']['subscription_details']['subscription'] ?? null);
    }

    private static function invoicePeriodEnd(array $invoice): ?Carbon
    {
        $ends = array_filter(array_map(fn ($l) => $l['period']['end'] ?? null, (array) ($invoice['lines']['data'] ?? [])), 'is_numeric');
        return $ends === [] ? null : self::time((int) max($ends));
    }

    private static function time(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp)->setTimezone((string) config('app.timezone'));
    }
}
