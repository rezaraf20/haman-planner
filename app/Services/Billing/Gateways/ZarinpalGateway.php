<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Models\Payment;
use App\Support\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Zarinpal (Iranian cards) — REST v4. Amounts are sent in tomans (currency=IRT).
 * Sandbox mode uses Zarinpal's own sandbox host; nothing is simulated locally.
 */
final class ZarinpalGateway implements PaymentGateway
{
    public function key(): string { return 'zarinpal'; }

    public function currency(): string { return 'IRT'; }

    private function config(string $key): mixed { return PaymentSettings::get('zarinpal_'.$key); }

    public function isConfigured(): bool
    {
        return (bool) $this->config('enabled') && filled($this->config('merchant_id'));
    }

    private function base(): string
    {
        return $this->config('sandbox') ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    public function start(Payment $payment, string $callbackUrl, string $description): CheckoutSession
    {
        $response = Http::timeout(15)->acceptJson()->post($this->base().'/pg/v4/payment/request.json', [
            'merchant_id' => (string) $this->config('merchant_id'),
            'amount' => (int) $payment->amount,
            'currency' => 'IRT',
            'description' => mb_substr($description, 0, 255),
            'callback_url' => $callbackUrl,
            'metadata' => array_filter(['email' => $payment->customer_email, 'order_id' => (string) $payment->id]),
        ]);
        $data = (array) $response->json('data');
        if ($response->failed() || (int) ($data['code'] ?? 0) !== 100 || empty($data['authority'])) {
            throw new GatewayException('Zarinpal request failed: '.$this->errorText($response->json()));
        }
        $authority = (string) $data['authority'];
        return new CheckoutSession($this->base().'/pg/StartPay/'.$authority, $authority);
    }

    public function verify(Payment $payment, Request $request): PaymentVerification
    {
        $authority = (string) $request->query('Authority', '');
        if ($authority === '' || !hash_equals((string) $payment->provider_reference, $authority)) {
            return new PaymentVerification(false, null, 'authority_mismatch');
        }
        if (strtoupper((string) $request->query('Status')) !== 'OK') {
            return new PaymentVerification(false, null, 'canceled_by_customer', true);
        }
        $response = Http::timeout(15)->acceptJson()->post($this->base().'/pg/v4/payment/verify.json', [
            'merchant_id' => (string) $this->config('merchant_id'),
            'amount' => (int) $payment->amount,
            'authority' => $authority,
        ]);
        $data = (array) $response->json('data');
        $code = (int) ($data['code'] ?? 0);
        if ($response->successful() && in_array($code, [100, 101], true)) {
            return new PaymentVerification(true, isset($data['ref_id']) ? (string) $data['ref_id'] : null, null, false, [
                'code' => $code, 'card_pan' => $data['card_pan'] ?? null, 'fee' => $data['fee'] ?? null,
            ]);
        }
        return new PaymentVerification(false, null, 'verify_failed: '.$this->errorText($response->json()));
    }

    private function errorText(mixed $json): string
    {
        $errors = is_array($json) ? ($json['errors'] ?? []) : [];
        if (is_array($errors) && isset($errors['code'])) {
            return $errors['code'].' '.($errors['message'] ?? '');
        }
        return is_array($json) && isset($json['data']['code']) ? 'code '.$json['data']['code'] : 'unexpected response';
    }
}
