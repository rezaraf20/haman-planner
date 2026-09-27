<?php
declare(strict_types=1);

namespace App\Support;

/** Formats integer minor-unit amounts for display (no floating point in storage). */
final class Money
{
    public static function format(int $amount, string $currency, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $decimals = (int) config("billing.currencies.$currency.decimals", 2);
        $major = $decimals > 0 ? $amount / (10 ** $decimals) : $amount;
        $number = number_format($major, ($decimals > 0 && $amount % (10 ** $decimals) !== 0) ? $decimals : 0, '.', ',');

        if ($locale === 'fa') {
            $digits = LocalDate::faDigits(str_replace(',', '٬', $number));
            return match ($currency) {
                'IRT' => $digits.' تومان',
                'USD' => $digits.' دلار',
                default => $digits.' '.$currency,
            };
        }
        return match ($currency) {
            'USD' => '$'.$number,
            'IRT' => $number.' toman',
            default => $number.' '.$currency,
        };
    }
}
