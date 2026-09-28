<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Recognises planning requests in Persian or English free text, before any AI call:
 * "برنامه امروزمو بچین" → day, "هفته آینده رو برنامه‌ریزی کن" → next_week,
 * "برنامه‌ام رو درست کن / عقب افتادم" → fix, "الان روی چی کار کنم؟" → now.
 */
final class PlanningIntent
{
    public static function detect(string $text): ?string
    {
        $t = mb_strtolower(str_replace(["\u{200C}", 'ي', 'ك'], [' ', 'ی', 'ک'], trim($text)));
        $has = fn (array $words) => (bool) array_filter($words, fn ($w) => str_contains($t, $w));

        if ($has(['الان روی چی', 'الان چی کار', 'الان چه کار', 'چی کار کنم', 'روی چه کاری', 'what should i work on', 'what should i do now', 'what now', 'work on now'])) {
            return 'now';
        }
        if ($has(['عقب افتاد', 'درستش کن', 'درست کن', 'بازبرنامه', 'fix my schedule', 'fix my plan', 'fell behind', 'behind schedule', 'reschedule'])) {
            return 'fix';
        }
        if ($has(['هفته آینده', 'هفته بعد', 'next week'])) {
            return 'next_week';
        }
        if ($has(['این هفته', 'هفته رو', 'هفته را', 'plan my week', 'this week', 'week plan'])) {
            return 'week';
        }
        if ($has(['امروز', 'امروزم', 'plan my day', 'today', 'plan today'])) {
            return 'day';
        }
        return null;
    }
}
