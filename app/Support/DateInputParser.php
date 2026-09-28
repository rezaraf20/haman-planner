<?php
declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Forgiving date/time parser for typed input (Telegram bot and other free-text fields).
 *
 * In a right-to-left chat the example "1405-07-03 14:30" is displayed visually as
 * "14:30 03-07-1405", so people type the time first or the year last. It also has to cope with
 * Persian/Arabic digits, invisible direction marks that keyboards and copy/paste insert,
 * Persian words ("ساعت", "فردا", "مهر", "عصر") and several separators. Everything is interpreted
 * in the given timezone; a year below 1700 is Jalali.
 *
 * Accepted, among others:
 *   1405-07-03 14:30 · 1405/7/3 ساعت ۱۴:۳۰ · 14:30 1405-07-03 · 03-07-1405 14:30 · 2026-09-30T09:00
 *   14:30 · ۱۴.۳۰ · ساعت ۱۰ · ۱۰ صبح · ۵ عصر · 5pm · امروز / فردا / پس‌فردا / دیروز [+ time]
 *   ۷ مهر [۱۴۰۵] [۱۰:۰۰] · Oct 7 · ۲ ساعت دیگه · 30 دقیقه دیگر · in 2 hours · now / الان · شنبه ۱۰:۰۰
 */
final class DateInputParser
{
    private const JALALI_MONTHS = [
        'فروردین' => 1, 'اردیبهشت' => 2, 'خرداد' => 3, 'تیر' => 4, 'مرداد' => 5, 'امرداد' => 5, 'شهریور' => 6,
        'مهر' => 7, 'آبان' => 8, 'اذر' => 9, 'آذر' => 9, 'دی' => 10, 'بهمن' => 11, 'اسفند' => 12,
    ];

    private const GREGORIAN_MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** Carbon dayOfWeek: 0 = Sunday … 6 = Saturday */
    private const WEEKDAYS = [
        'یکشنبه' => 0, 'دوشنبه' => 1, 'سه شنبه' => 2, 'سهشنبه' => 2, 'چهارشنبه' => 3, 'پنجشنبه' => 4, 'پنج شنبه' => 4, 'جمعه' => 5, 'شنبه' => 6,
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];

    /**
     * @param bool $withTime when no time is given: 09:00 for datetimes, start of day for dates
     * @param string $locale decides how a date without a year is read (fa → Jalali month/day, en → Gregorian)
     */
    public static function parse(string $input, string $timezone, bool $withTime = true, string $locale = 'fa', ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->second(0)->microsecond(0);
        $v = self::normalize($input);
        if ($v === '') {
            return null;
        }

        if (in_array($v, ['now', 'اکنون', 'الان', 'همین الان', 'همین حالا', 'حالا'], true)) {
            return $now;
        }
        // "2 ساعت دیگه", "30 دقیقه دیگر", "in 2 hours", "یک ساعت بعد"
        $v = str_replace(['یک ', 'نیم '], ['1 ', '0.5 '], $v);
        if (preg_match('/^(?:in\s+)?(\d+(?:\.\d+)?)\s*(دقیقه|min|mins|minute|minutes|ساعت|hour|hours|h|روز|day|days)\s*(?:دیگه|دیگر|بعد|later)?$/u', $v, $m)
            && (str_starts_with($v, 'in ') || preg_match('/(دیگه|دیگر|بعد|later)$/u', $v))) {
            $n = (float) $m[1];
            $minutes = match (true) {
                in_array($m[2], ['دقیقه', 'min', 'mins', 'minute', 'minutes'], true) => $n,
                in_array($m[2], ['روز', 'day', 'days'], true) => $n * 1440,
                default => $n * 60,
            };
            return $minutes > 0 && $minutes <= 525600 ? $now->addMinutes((int) round($minutes)) : null;
        }

        // ---- time part (anywhere in the text)
        [$time, $v] = self::extractTime($v);
        if ($time === false) {
            return null; // looked like a time but was out of range
        }

        // ---- date part
        $date = self::extractDate($v, $now, $locale);
        if ($date === false) {
            return null;
        }
        if ($date === null) {
            if ($time === null) {
                return null;
            }
            $date = $now->startOfDay();
        }
        if ($time !== null) {
            return $date->setTime($time[0], $time[1]);
        }
        return $withTime ? $date->setTime(9, 0) : $date->startOfDay();
    }

    /** Latin digits, no direction marks, single spaces, common separators unified. */
    public static function normalize(string $input): string
    {
        $v = LocalDate::latinDigits($input);
        // Bidi controls, zero-width characters and the Arabic letter mark.
        $v = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}\x{FEFF}]/u', '', $v);
        $v = str_replace(["\u{200C}", "\u{00A0}", "\u{202F}", '‌'], ' ', $v); // ZWNJ / no-break spaces → space
        $v = str_replace(['ي', 'ك', '：', '٫', '،', '؛'], ['ی', 'ک', ':', '.', ' ', ' '], $v);
        $v = mb_strtolower(trim($v));
        $v = str_replace('پس فردا', 'پسفردا', $v);
        $v = (string) preg_replace(['/(\d)t(\d)/', '/(\d)\s*(am|pm|a\.m\.|p\.m\.)(?=\s|$)/'], ['$1 $2', '$1 $2'], $v);
        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }

    /** @return array{0: array{0:int,1:int}|null|false, 1: string} [time or null/false, remaining text] */
    private static function extractTime(string $v): array
    {
        // "5 عصر", "ساعت ۸:۳۰ صبح", "5 pm": the number right before the marker is the hour.
        $markers = '(عصر|بعدازظهر|بعد از ظهر|بعد ازظهر|ب\.ظ|شب|pm|p\.m\.|صبح|ق\.ظ|am|a\.m\.|ظهر)';
        if (preg_match('/(?:^|\s)(?:ساعت\s*|at\s*)?(\d{1,2})(?:[:.](\d{2}))?\s+'.$markers.'(?=\s|$)/u', $v, $mm, PREG_OFFSET_CAPTURE)) {
            $h = (int) $mm[1][0];
            $min = isset($mm[2]) && $mm[2][0] !== '' ? (int) $mm[2][0] : 0;
            $marker = $mm[3][0];
            $rest = trim((string) preg_replace('/\s+/u', ' ', substr_replace($v, ' ', $mm[0][1], strlen($mm[0][0]))));
            if (in_array($marker, ['عصر', 'بعدازظهر', 'بعد از ظهر', 'بعد ازظهر', 'ب.ظ', 'شب', 'pm', 'p.m.'], true) && $h < 12) {
                $h += 12;
            } elseif (in_array($marker, ['صبح', 'ق.ظ', 'am', 'a.m.'], true) && $h === 12) {
                $h = 0;
            }
            return $h > 23 || $min > 59 ? [false, $rest] : [[$h, $min], trim($rest, ' -,')];
        }
        $pm = (bool) preg_match('/(?:^|\s)(عصر|بعدازظهر|بعد از ظهر|ب\.ظ|بعد ازظهر|شب|pm|p\.m\.)(?:\s|$)/u', $v);
        $am = (bool) preg_match('/(?:^|\s)(صبح|ق\.ظ|am|a\.m\.)(?:\s|$)/u', $v);
        $noon = (bool) preg_match('/(?:^|\s)ظهر(?:\s|$)/u', $v) && !$pm;
        $v = trim((string) preg_replace('/(?:^|\s)(عصر|بعدازظهر|بعد از ظهر|بعد ازظهر|ب\.ظ|شب|pm|p\.m\.|صبح|ق\.ظ|am|a\.m\.|ظهر)(?=\s|$)/u', ' ', $v));
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));

        $h = null;
        $min = 0;
        // HH:MM (optionally :SS), also "14.30" when it is clearly a time (not part of a date).
        if (preg_match('/(?:^|[\s\-])(\d{1,2}):(\d{2})(?::\d{2})?(?=\s|$)/u', $v, $m, PREG_OFFSET_CAPTURE)) {
            [$h, $min] = [(int) $m[1][0], (int) $m[2][0]];
            $v = substr_replace($v, ' ', $m[0][1], strlen($m[0][0]));
        } elseif (preg_match('/(?:^|\s)(?:ساعت|at)\s*(\d{1,2})(?:[.:](\d{2}))?(?=\s|$)/u', $v, $m, PREG_OFFSET_CAPTURE)) {
            [$h, $min] = [(int) $m[1][0], isset($m[2]) && $m[2][0] !== '' ? (int) $m[2][0] : 0];
            $v = substr_replace($v, ' ', $m[0][1], strlen($m[0][0]));
        } elseif (preg_match('/^(\d{1,2})\.(\d{2})$/u', trim($v), $m)) {
            [$h, $min] = [(int) $m[1], (int) $m[2]];
            $v = '';
        } elseif (($pm || $am || $noon) && preg_match('/(?:^|\s)(\d{1,2})(?=\s|$)/u', $v, $m, PREG_OFFSET_CAPTURE)) {
            [$h, $min] = [(int) $m[1][0], 0];
            $v = substr_replace($v, ' ', $m[0][1], strlen($m[0][0]));
        } elseif (preg_match('/^(\d{1,2})$/u', trim($v), $m)) {
            // A bare number is an hour ("10" → 10:00).
            [$h, $min] = [(int) $m[1], 0];
            $v = '';
        } elseif ($noon) {
            [$h, $min] = [12, 0];
        }
        $v = trim((string) preg_replace(['/(?:^|\s)(ساعت|at)(?=\s|$)/u', '/\s+/u'], [' ', ' '], $v));
        $v = trim($v, " -,");
        if ($h === null) {
            return [null, $v];
        }
        if ($pm && $h < 12) {
            $h += 12;
        } elseif ($am && $h === 12) {
            $h = 0;
        }
        if ($h > 23 || $min > 59) {
            return [false, $v];
        }
        return [[$h, $min], $v];
    }

    /** @return CarbonImmutable|null|false null = no date given, false = invalid date */
    private static function extractDate(string $v, CarbonImmutable $now, string $locale): CarbonImmutable|null|false
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $today = $now->startOfDay();
        $relative = ['today' => 0, 'امروز' => 0, 'tomorrow' => 1, 'فردا' => 1, 'پسفردا' => 2, 'yesterday' => -1, 'دیروز' => -1];
        if (isset($relative[$v])) {
            return $today->addDays($relative[$v]);
        }
        foreach (self::WEEKDAYS as $name => $dow) {
            if ($v === $name || $v === $name.' بعد' || $v === 'next '.$name || $v === $name.' آینده') {
                $days = ($dow - $today->dayOfWeek + 7) % 7;
                return $today->addDays($days === 0 ? 7 : $days);
            }
        }

        // Numeric dates with -, /, . separators, in either order (Y-M-D or D-M-Y).
        if (preg_match('/^(\d{1,4})[\-\/.](\d{1,2})[\-\/.](\d{1,4})$/', $v, $m)) {
            [$a, $b, $c] = [(int) $m[1], $m[2], (int) $m[3]];
            if (strlen($m[1]) === 4) {
                return self::make($a, (int) $b, $c, $now);
            }
            if (strlen($m[3]) === 4) {
                return self::make($c, (int) $b, $a, $now); // D-M-Y as shown by right-to-left chats
            }
            return false;
        }
        // Month/day without a year: fa → Jalali, en → Gregorian (M/D).
        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})$/', $v, $m)) {
            [$mo, $d] = [(int) $m[1], (int) $m[2]];
            return self::withoutYear($mo, $d, $now, $locale === 'fa');
        }
        // "۷ مهر [۱۴۰۵]", "مهر ۷", "7 oct 2026", "oct 7"
        $tokens = explode(' ', $v);
        $month = null;
        $jalali = false;
        $nums = [];
        foreach ($tokens as $t) {
            if (isset(self::JALALI_MONTHS[$t])) {
                $month = self::JALALI_MONTHS[$t];
                $jalali = true;
            } elseif (isset(self::GREGORIAN_MONTHS[mb_substr($t, 0, 3)]) && preg_match('/^[a-z]+\.?$/', $t)) {
                $month = self::GREGORIAN_MONTHS[mb_substr($t, 0, 3)];
            } elseif (ctype_digit($t)) {
                $nums[] = (int) $t;
            } elseif (!in_array($t, ['ماه', 'سال', 'of', 'the', 'روز'], true)) {
                return false;
            }
        }
        if ($month !== null && $nums !== [] && count($nums) <= 2) {
            $day = $nums[0] <= 31 ? $nums[0] : ($nums[1] ?? 0);
            $year = count($nums) === 2 ? ($nums[0] > 31 ? $nums[0] : $nums[1]) : null;
            if ($year === null) {
                return self::withoutYear($month, $day, $now, $jalali);
            }
            if ($jalali && $year >= 1700) {
                return false;
            }
            return self::make($year, $month, $day, $now);
        }
        return false;
    }

    private static function make(int $y, int $mo, int $d, CarbonImmutable $now): CarbonImmutable|false
    {
        if ($y < 1700) {
            if ($y < 1300 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
                return false;
            }
            [$gy, $gm, $gd] = LocalDate::jalaliToGregorian($y, $mo, $d);
            if (LocalDate::gregorianToJalali($gy, $gm, $gd) !== [$y, $mo, $d]) {
                return false; // e.g. 30 Esfand in a common year, 31 Mehr
            }
            [$y, $mo, $d] = [$gy, $gm, $gd];
        }
        if ($y > 2200 || !checkdate($mo, $d, $y)) {
            return false;
        }
        return CarbonImmutable::create($y, $mo, $d, 0, 0, 0, $now->getTimezone());
    }

    /** Next occurrence of month/day (this year, or next year if it already passed more than a day ago). */
    private static function withoutYear(int $mo, int $d, CarbonImmutable $now, bool $jalali): CarbonImmutable|false
    {
        if ($jalali) {
            [$jy] = LocalDate::gregorianToJalali($now->year, $now->month, $now->day);
            $date = self::make($jy, $mo, $d, $now);
            if ($date !== false && $date->lt($now->startOfDay()->subDay())) {
                $date = self::make($jy + 1, $mo, $d, $now);
            }
            return $date;
        }
        $date = self::make($now->year, $mo, $d, $now);
        if ($date !== false && $date->lt($now->startOfDay()->subDay())) {
            $date = self::make($now->year + 1, $mo, $d, $now);
        }
        return $date;
    }
}
