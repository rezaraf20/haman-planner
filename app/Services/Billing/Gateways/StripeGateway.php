<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Models\Payment;
use App\Models\Subscription;
use App\Support\PaymentSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout (international cards).
 *
 *  - Without a webhook signing secret: one-time "payment" mode — each paid period is a separate
 *    checkout, exactly like Zarinpal (the original behaviour).
 *  - With STRIPE_WEBHOOK_SECRET (or the admin panel value): "subscription" mode — Stripe renews
 *    automatically and reports renewals/failures/cancellations through signed webhooks.
 *
 * Verification always asks Stripe; nothing is trusted from the browser redirect alone.
 * Test mode = Stripe test keys (sk_test_…).
 */
final class StripeGateway implements RecurringGateway
{
    private const API = 'https://api.stripe.com/v1/';
    public const SIGNATURE_TOLERANCE = 300;

    public function key(): string { return 'stripe'; }

    public function currency(): string { return 'USD'; }

    private function secret(): string { return (string) PaymentSettings::get('stripe_secret'); }

    public function webhookSecret(): string { return (string) PaymentSettings::get('stripe_webhook_secret'); }

    public function isConfigured(): bool
    {
        return (bool) PaymentSettings::get('stripe_enabled') && $this->secret() !== '';
    }

    public function supportsRecurring(): bool
    {
        return $this->isConfigured() && $this->webhookSecret() !== '';
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)->withToken($this->secret())->asForm();
    }

    public function start(Payment $payment, string $callbackUrl, string $description): CheckoutSession
    {
        $sep = str_contains($callbackUrl, '?') ? '&' : '?';
        $recurring = ($payment->meta['mode'] ?? null) === 'subscription';
        $params = [
            'mode' => $recurring ? 'subscription' : 'payment',
            'success_url' => $callbackUrl.$sep.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $callbackUrl.$sep.'canceled=1',
            'client_reference_id' => (string) $payment->id,
            'customer_email' => $payment->customer_email,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => (int) $payment->amount,
            'line_items[0][price_data][product_data][name]' => mb_substr($description, 0, 200),
            'metadata[payment_id]' => (string) $payment->id,
        ];
        if ($recurring) {
            $params['line_items[0][price_data][recurring][interval]'] = $payment->billing_interval === 'yearly' ? 'year' : 'month';
            $params['subscription_data[metadata][payment_id]'] = (string) $payment->id;
            $params['subscription_data[metadata][user_id]'] = (string) $payment->user_id;
            $params['subscription_data[metadata][plan_id]'] = (string) $payment->plan_id;
        }
        $response = $this->http()->post(self::API.'checkout/sessions', array_filter($params, fn ($v) => $v !== null));
        if ($response->failed() || !$response->json('id') || !$response->json('url')) {
            throw new GatewayException('Stripe checkout failed: '.($response->json('error.message') ?? $response->status()));
        }
        return new CheckoutSession((string) $response->json('url'), (string) $response->json('id'));
    }

    public function verify(Payment $payment, Request $request): PaymentVerification
    {
        if ($request->boolean('canceled')) {
            return new PaymentVerification(false, null, 'canceled_by_customer', true);
        }
        $sessionId = (string) $request->query('session_id', $request->input('session_id', ''));
        if ($sessionId === '' || !hash_equals((string) $payment->provider_reference, $sessionId)) {
            return new PaymentVerification(false, null, 'session_mismatch');
        }
        $response = Http::timeout(20)->withToken($this->secret())->get(self::API.'checkout/sessions/'.rawurlencode($sessionId));
        if ($response->serverError() || $response->status() === 429) {
            // Temporary provider problem: the payment stays pending and is verified again later
            // (next callback hit or the checkout.session.completed webhook).
            throw new GatewayException('Stripe verification temporarily failed: HTTP '.$response->status());
        }
        if ($response->failed()) {
            return new PaymentVerification(false, null, 'verify_failed: HTTP '.$response->status());
        }
        $s = (array) $response->json();
        $recurring = ($payment->meta['mode'] ?? null) === 'subscription';
        $ok = ($s['payment_status'] ?? null) === 'paid'
            && (int) ($s['amount_total'] ?? -1) === (int) $payment->amount
            && strtolower((string) ($s['currency'] ?? '')) === 'usd'
            && (string) ($s['client_reference_id'] ?? '') === (string) $payment->id
            && (!$recurring || (($s['mode'] ?? null) === 'subscription' && is_string(self::id($s['subscription'] ?? null))));
        if (!$ok) {
            return new PaymentVerification(false, null, 'not_paid: '.($s['payment_status'] ?? 'unknown'));
        }
        $raw = ['payment_status' => 'paid'];
        if ($recurring) {
            $raw += ['subscription' => self::id($s['subscription'] ?? null), 'customer' => self::id($s['customer'] ?? null)];
        }
        $reference = self::id($s['payment_intent'] ?? null) ?? self::id($s['invoice'] ?? null) ?? $sessionId;
        return new PaymentVerification(true, $reference, null, false, $raw);
    }

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel): void
    {
        $r = $this->http()->post(self::API.'subscriptions/'.rawurlencode((string) $subscription->provider_reference), ['cancel_at_period_end' => $cancel ? 'true' : 'false']);
        if ($r->failed()) {
            throw new GatewayException('Stripe subscription update failed: '.($r->json('error.message') ?? $r->status()));
        }
    }

    /** Current state of a subscription straight from Stripe (webhook events can arrive out of order). */
    public function retrieveSubscription(string $id): array
    {
        $r = Http::timeout(20)->withToken($this->secret())->get(self::API.'subscriptions/'.rawurlencode($id));
        if ($r->failed() || !is_array($r->json())) {
            throw new GatewayException('Stripe subscription lookup failed: HTTP '.$r->status());
        }
        return (array) $r->json();
    }

    public function cancelNow(Subscription $subscription): void
    {
        $this->cancelById((string) $subscription->provider_reference);
    }

    public function cancelById(string $id): void
    {
        $r = Http::timeout(20)->withToken($this->secret())->delete(self::API.'subscriptions/'.rawurlencode($id));
        if ($r->failed() && $r->status() !== 404) {
            throw new GatewayException('Stripe subscription cancel failed: '.($r->json('error.message') ?? $r->status()));
        }
    }

    /**
     * Verify a webhook's Stripe-Signature header (HMAC-SHA256 of "timestamp.payload") and
     * return the decoded event, or null when the signature, timestamp or body is invalid.
     */
    public function parseWebhook(string $payload, string $header, ?int $now = null): ?array
    {
        $secret = $this->webhookSecret();
        if ($secret === '' || $header === '') {
            return null;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return null;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = false;
        foreach ($signatures as $sig) {
            $valid = $valid || hash_equals($expected, $sig);
        }
        if (!$valid) {
            return null;
        }
        $event = json_decode($payload, true);
        return is_array($event) && is_string($event['id'] ?? null) && is_string($event['type'] ?? null) ? $event : null;
    }

    /** Stripe fields may be an ID string or an expanded object. */
    public static function id(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null;
    }
}
