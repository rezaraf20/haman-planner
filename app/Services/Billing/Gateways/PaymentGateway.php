<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * A payment provider. Implementations talk to the provider's real API; there is no
 * simulated success path. A provider only appears in the UI when isConfigured() is true.
 */
interface PaymentGateway
{
    public function key(): string;

    public function currency(): string;

    public function isConfigured(): bool;

    /** Create a checkout with the provider and return where to send the customer. */
    public function start(Payment $payment, string $callbackUrl, string $description): CheckoutSession;

    /** Confirm with the provider (server-to-server) whether the payment really succeeded. */
    public function verify(Payment $payment, Request $request): PaymentVerification;
}
