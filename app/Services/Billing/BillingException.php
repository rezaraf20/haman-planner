<?php
declare(strict_types=1);

namespace App\Services\Billing;

use RuntimeException;

/** A billing action the user cannot take; the message is a translation key under billing.errors. */
final class BillingException extends RuntimeException {}
