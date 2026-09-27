<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Models\Payment;
use App\Support\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout (international cards) in one-time "payment" mode: each paid period is a
 * separate checkout, which keeps renewals identical across providers. Test mode = Stripe
 * test keys (sk_test_…); verification always asks Stripe for the session's payment status.
 */
final class StripeGateway implements PaymentGateway
{
    public function key(): string { return 'stripe'; }

    public function currency(): string { return 'USD'; }

    private function secret(): string { return (string) PaymentSettings::get('stripe_secret'); }

    public function isConfigured(): bool
    {
        return (bool) PaymentSettings::get('stripe_enabled') && $this->secret() !== '';
    }

    public function start(Payment $payment, string $callbackUrl, string $description): CheckoutSession
    {
        $sep = str_contains($callbackUrl, '?') ? '&' : '?';
        $response = Http::timeout(20)->withToken($this->secret())->asForm()->post('https://api.stripe.com/v1/checkout/sessions', array_filter([
            'mode' => 'payment',
            'success_url' => $callbackUrl.$sep.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $callbackUrl.$sep.'canceled=1',
            'client_reference_id' => (string) $payment->id,
            'customer_email' => $payment->customer_email,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => (int) $payment->amount,
            'line_items[0][price_data][product_data][name]' => mb_substr($description, 0, 200),
            'metadata[payment_id]' => (string) $payment->id,
        ], fn ($v) => $v !== null));
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
        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId === '' || !hash_equals((string) $payment->provider_reference, $sessionId)) {
            return new PaymentVerification(false, null, 'session_mismatch');
        }
        $response = Http::timeout(20)->withToken($this->secret())->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId));
        if ($response->failed()) {
            return new PaymentVerification(false, null, 'verify_failed: HTTP '.$response->status());
        }
        $s = (array) $response->json();
        $ok = ($s['payment_status'] ?? null) === 'paid'
            && (int) ($s['amount_total'] ?? -1) === (int) $payment->amount
            && strtolower((string) ($s['currency'] ?? '')) === 'usd'
            && (string) ($s['client_reference_id'] ?? '') === (string) $payment->id;
        return $ok
            ? new PaymentVerification(true, (string) ($s['payment_intent'] ?? $sessionId), null, false, ['payment_status' => 'paid'])
            : new PaymentVerification(false, null, 'not_paid: '.($s['payment_status'] ?? 'unknown'));
    }
}
