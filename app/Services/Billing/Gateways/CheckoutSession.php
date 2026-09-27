<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

final class CheckoutSession
{
    public function __construct(public readonly string $redirectUrl, public readonly string $reference) {}
}
