<?php
declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Locale-aware display of stored date/times (storage stays in the app timezone).
 *  fa: Jalali calendar with Persian digits, e.g. «۱۴۰۵/۰۷/۰۵ ۱۴:۳۰»
 *  en: Gregorian, e.g. "Sep 27, 2026 2:30 PM"
 */
final class LocalDate
{
    private const FA_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    public static function tz(): string
    {
        $user = auth()->user();
        return $user instanceof \App\Models\User ? $user->preferredTimezone() : (string) config('app.timezone');
    }

    public static function toCarbon(mixed $value, ?string $tz = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $c = $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value, (string) config('app.timezone'));
        return $c->copy()->setTimezone($tz ?? self::tz());
    }

    public static function date(mixed $value, ?string $locale = null, ?string $tz = null): string
    {
        $c = self::toCarbon($value, $tz);
        if (!$c) {
            return '—';
        }
        if (($locale ?? app()->getLocale()) === 'fa') {
            [$y, $m, $d] = self::gregorianToJalali((int) $c->year, (int) $c->month, (int) $c->day);
            return self::faDigits(sprintf('%04d/%02d/%02d', $y, $m, $d));
        }
        return $c->format('M j, Y');
    }

    public static function dateTime(mixed $value, ?string $locale = null, ?string $tz = null): string
    {
        $c = self::toCarbon($value, $tz);
        if (!$c) {
            return '—';
        }
        if (($locale ?? app()->getLocale()) === 'fa') {
            return self::date($c, 'fa', $tz ?? $c->getTimezone()->getName()).' '.self::faDigits($c->format('H:i'));
        }
        return $c->format('M j, Y g:i A');
    }

    /** Short "month day time" form for dense lists (Telegram). */
    public static function short(mixed $value, ?string $locale = null, ?string $tz = null, bool $withTime = true): string
    {
        $c = self::toCarbon($value, $tz);
        if (!$c) {
            return '—';
        }
        if (($locale ?? app()->getLocale()) === 'fa') {
            [, $m, $d] = self::gregorianToJalali((int) $c->year, (int) $c->month, (int) $c->day);
            return self::faDigits($d.' '.self::FA_MONTHS[$m - 1].($withTime ? ' '.$c->format('H:i') : ''));
        }
        return $c->format($withTime ? 'M j H:i' : 'M j');
    }

    public static function time(mixed $value, ?string $locale = null, ?string $tz = null): string
    {
        $c = self::toCarbon($value, $tz);
        if (!$c) {
            return '—';
        }
        return ($locale ?? app()->getLocale()) === 'fa' ? self::faDigits($c->format('H:i')) : $c->format('g:i A');
    }

    public static function weekday(CarbonInterface $c, ?string $locale = null): string
    {
        if (($locale ?? app()->getLocale()) === 'fa') {
            return ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'][(int) $c->dayOfWeek];
        }
        return $c->format('D');
    }

    public static function number(int|float|string|null $value, ?string $locale = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $s = is_numeric($value) ? (is_float($value + 0) && floor((float) $value) != (float) $value ? rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') : number_format((float) $value, 0, '.', ',')) : (string) $value;
        return ($locale ?? app()->getLocale()) === 'fa' ? self::faDigits(str_replace(',', '٬', $s)) : $s;
    }

    public static function faDigits(string $s): string
    {
        return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    public static function latinDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** @return array{0:int,1:int,2:int} */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /** @return array{0:int,1:int,2:int} */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
        $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) {
            $gd -= $months[$gm];
        }
        return [$gy, $gm, $gd];
    }
}
