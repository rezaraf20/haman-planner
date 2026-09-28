<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\RecurringTask;

/** Human-readable description of a recurrence rule, in the current interface language. */
final class RecurrenceLabel
{
    public static function for(RecurringTask $s, ?string $locale = null): string
    {
        $n = max(1, (int) $s->interval);
        $num = fn ($v) => LocalDate::number($v, $locale);
        $time = $s->time_of_day ? ' '.__('recurrence.at_time', ['time' => LocalDate::number($s->time_of_day, $locale)], $locale) : '';
        $jalali = $s->calendar === 'jalali';

        $text = match ($s->frequency) {
            'daily' => $n === 1 ? __('recurrence.every_day', [], $locale) : __('recurrence.every_n_days', ['n' => $num($n)], $locale),
            'weekly' => self::weekly($s, $n, $locale),
            'monthly' => __($n === 1 ? 'recurrence.every_month_on' : 'recurrence.every_n_months_on', [
                'n' => $num($n), 'day' => $num($s->by_month_day ?? self::startDay($s, $jalali)),
            ], $locale).($jalali ? ' '.__('recurrence.jalali_suffix', [], $locale) : ''),
            'yearly' => __($n === 1 ? 'recurrence.every_year_on' : 'recurrence.every_n_years_on', [
                'n' => $num($n),
                'date' => $num($s->by_month_day ?? self::startDay($s, $jalali)).' '
                    .(__($jalali ? 'recurrence.months_jalali' : 'recurrence.months', [], $locale)[($s->by_month ?? self::startMonth($s, $jalali)) - 1] ?? ''),
            ], $locale),
            default => (string) $s->frequency,
        };
        if ($s->ends_on) {
            $text .= ' · '.__('recurrence.until', ['date' => LocalDate::date($s->ends_on, $locale)], $locale);
        } elseif ($s->max_occurrences) {
            $text .= ' · '.__('recurrence.times', ['n' => $num($s->max_occurrences)], $locale);
        }
        return $text.$time;
    }

    private static function weekly(RecurringTask $s, int $n, ?string $locale): string
    {
        $days = array_map('intval', (array) ($s->by_weekday ?: [$s->starts_on->dayOfWeekIso]));
        $names = __('recurrence.weekdays', [], $locale); // ISO 1..7
        $order = ($locale ?? app()->getLocale()) === 'fa' ? [6, 7, 1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6, 7];
        $list = implode(__('recurrence.list_sep', [], $locale), array_map(fn ($d) => $names[$d - 1] ?? $d, array_values(array_filter($order, fn ($d) => in_array($d, $days, true)))));
        return __($n === 1 ? 'recurrence.every_week_on' : 'recurrence.every_n_weeks_on', ['n' => LocalDate::number($n, $locale), 'days' => $list], $locale);
    }

    private static function startDay(RecurringTask $s, bool $jalali): int
    {
        $d = $s->starts_on;
        return $jalali ? LocalDate::gregorianToJalali($d->year, $d->month, $d->day)[2] : $d->day;
    }

    private static function startMonth(RecurringTask $s, bool $jalali): int
    {
        $d = $s->starts_on;
        return $jalali ? LocalDate::gregorianToJalali($d->year, $d->month, $d->day)[1] : $d->month;
    }
}
