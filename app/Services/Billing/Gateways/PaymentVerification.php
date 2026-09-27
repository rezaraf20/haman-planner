<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

final class PaymentVerification
{
    /** @param array<string,mixed> $raw safe subset of the provider response */
    public function __construct(
        public readonly bool $paid,
        public readonly ?string $transactionReference = null,
        public readonly ?string $failureReason = null,
        public readonly bool $canceledByCustomer = false,
        public readonly array $raw = [],
    ) {}
}
