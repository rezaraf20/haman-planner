<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Models\Payment;
use App\Support\PaymentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Zibal IPG (Iranian bank cards).
 *
 *   POST https://gateway.zibal.ir/v1/request  {merchant, amount, callbackUrl, orderId, description} → {result, trackId}
 *   redirect https://gateway.zibal.ir/start/{trackId}
 *   callback GET ?success=&status=&trackId=&orderId=     (never trusted on its own)
 *   POST https://gateway.zibal.ir/v1/verify   {merchant, trackId} → {result, amount, refNumber, orderId, status, cardNumber}
 *   POST https://gateway.zibal.ir/v1/inquiry  {merchant, trackId} (used when verify answers 201 "already verified")
 *
 * CURRENCY — the single conversion boundary of this integration:
 *   Haman Planner prices, payments and invoices are in Toman (currency "IRT").
 *   Zibal's API takes and returns amounts in **Rial**. 1 Toman = 10 Rial.
 *   toRial() is used for every amount sent to Zibal and every amount Zibal reports is compared
 *   against toRial(payment amount). Nothing outside this class knows about Rial.
 *
 * Sandbox: Zibal's documented test merchant code is "zibal" (no real money moves).
 */
final class ZibalGateway implements PaymentGateway
{
    public const BASE = 'https://gateway.zibal.ir';
    public const SANDBOX_MERCHANT = 'zibal';
    public const MIN_RIAL = 1000;

    public function key(): string { return 'zibal'; }

    public function currency(): string { return 'IRT'; }

    /** The only Toman → Rial conversion in the application. */
    public static function toRial(int $toman): int
    {
        if ($toman < 0) {
            throw new \InvalidArgumentException('Negative amount');
        }
        return $toman * 10;
    }

    private function config(string $key): mixed { return PaymentSettings::get('zibal_'.$key); }

    private function merchant(): string
    {
        return $this->config('sandbox') ? self::SANDBOX_MERCHANT : (string) $this->config('merchant');
    }

    public function isConfigured(): bool
    {
        return (bool) $this->config('enabled') && ($this->config('sandbox') || filled($this->config('merchant')));
    }

    public function start(Payment $payment, string $callbackUrl, string $description): CheckoutSession
    {
        $rial = self::toRial((int) $payment->amount);
        if ($rial < self::MIN_RIAL) {
            throw new GatewayException('Zibal: amount below the gateway minimum');
        }
        $response = Http::timeout(15)->acceptJson()->asJson()->post(self::BASE.'/v1/request', [
            'merchant' => $this->merchant(),
            'amount' => $rial,
            // Zibal appends its own query string, so the return URL carries no signature;
            // the payment is found by trackId and always verified server-side.
            'callbackUrl' => route('billing.return.zibal'),
            'orderId' => (string) $payment->id,
            'description' => mb_substr($description, 0, 255),
        ]);
        $result = (int) $response->json('result', 0);
        $trackId = $response->json('trackId');
        if ($response->failed() || $result !== 100 || !is_numeric($trackId)) {
            throw new GatewayException('Zibal request failed: result '.$result.' '.mb_substr((string) $response->json('message', ''), 0, 120));
        }
        $trackId = (string) $trackId;
        return new CheckoutSession(self::BASE.'/start/'.rawurlencode($trackId), $trackId);
    }

    public function verify(Payment $payment, Request $request): PaymentVerification
    {
        $trackId = (string) $request->query('trackId', '');
        if ($trackId === '' || !hash_equals((string) $payment->provider_reference, $trackId)) {
            return new PaymentVerification(false, null, 'track_mismatch');
        }
        $orderId = (string) $request->query('orderId', '');
        if ($orderId !== '' && $orderId !== (string) $payment->id) {
            return new PaymentVerification(false, null, 'order_mismatch');
        }

        // The success/status query values come from the browser: they are never trusted.
        // Zibal's verify call is the only source of truth.
        $data = $this->call('/v1/verify', $trackId);
        $result = (int) ($data['result'] ?? 0);
        if ($result === 201) {
            // Already verified earlier (e.g. a repeated callback): confirm through inquiry.
            $data = $this->call('/v1/inquiry', $trackId);
            $paid = (int) ($data['result'] ?? 0) === 100 && (int) ($data['status'] ?? 0) === 1;
        } else {
            $paid = $result === 100;
        }
        if (!$paid) {
            $status = (int) $request->query('status', 0);
            $canceled = $result === 202 && $status === 3; // 3 = canceled by the customer
            return new PaymentVerification(false, null, $canceled ? 'canceled_by_customer' : 'not_paid: result '.$result, $canceled);
        }
        if ((int) ($data['amount'] ?? -1) !== self::toRial((int) $payment->amount)) {
            return new PaymentVerification(false, null, 'amount_mismatch');
        }
        if (isset($data['orderId']) && $data['orderId'] !== null && (string) $data['orderId'] !== (string) $payment->id) {
            return new PaymentVerification(false, null, 'order_mismatch');
        }
        $ref = isset($data['refNumber']) ? (string) $data['refNumber'] : $trackId;
        return new PaymentVerification(true, $ref, null, false, [
            'result' => $result, 'status' => $data['status'] ?? null,
            'card' => isset($data['cardNumber']) ? self::maskCard((string) $data['cardNumber']) : null,
            'amount_rial' => (int) $data['amount'],
        ]);
    }

    /** @return array<string,mixed> */
    private function call(string $path, string $trackId): array
    {
        try {
            $response = Http::timeout(15)->acceptJson()->asJson()->post(self::BASE.$path, ['merchant' => $this->merchant(), 'trackId' => (int) $trackId]);
        } catch (ConnectionException $e) {
            throw new GatewayException('Zibal unreachable: '.$e->getMessage());
        }
        if ($response->serverError() || $response->status() === 429) {
            // Temporary: the payment stays pending and is verified again on the next callback.
            throw new GatewayException('Zibal temporarily failed: HTTP '.$response->status());
        }
        return (array) $response->json();
    }

    private static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D/', '', $card) ?? '';
        return strlen($digits) >= 10 ? substr($digits, 0, 6).'******'.substr($digits, -4) : $card;
    }
}
