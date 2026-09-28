<?php
declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Models\Subscription;

/**
 * A gateway that can also run provider-managed, auto-renewing subscriptions. Renewals,
 * failures and cancellations then arrive as signed webhooks; the browser redirect only
 * activates the first period after a server-side check.
 */
interface RecurringGateway extends PaymentGateway
{
    /** True only when renewals can be confirmed server-to-server (e.g. a webhook secret is set). */
    public function supportsRecurring(): bool;

    /** Ask the provider to stop (true) or keep (false) renewing at the end of the current period. */
    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel): void;

    /** End the provider subscription immediately (used when the customer switches plans). */
    public function cancelNow(Subscription $subscription): void;
}
