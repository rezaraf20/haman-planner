<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown when an action would exceed the user's plan (limit or missing feature). */
final class PlanLimitReached extends RuntimeException
{
    public function __construct(public readonly string $metric, public readonly ?int $limit = null)
    {
        parent::__construct('Plan limit reached: '.$metric);
    }

    public function userMessage(?string $locale = null): string
    {
        $key = 'billing.limit.'.$this->metric;
        $text = __($key, ['limit' => (string) $this->limit], $locale);
        return $text === $key ? __('billing.limit.generic', [], $locale) : $text;
    }
}
