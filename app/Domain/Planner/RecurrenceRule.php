<?php
declare(strict_types=1);

namespace App\Domain\Planner;

use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A recurrence rule evaluated on local calendar dates (the series' own timezone is applied
 * later, when an occurrence date is turned into a start time).
 *
 *  daily    every `interval` days from starts_on
 *  weekly   on `byWeekday` (ISO 1 = Monday … 7 = Sunday) every `interval` weeks
 *  monthly  on `byMonthDay` (default: the start day) every `interval` months — clamped to the
 *           last day of shorter months (the 31st becomes 30 April, 28/29 February)
 *  yearly   on `byMonth`/`byMonthDay` every `interval` years (29 Feb → 28 Feb in common years)
 *
 * With calendar = "jalali", monthly/yearly days and months are Solar Hijri (Persian) ones:
 * "every month on the 1st" is the 1st of each Jalali month; the 31st is clamped to 30 in the
 * second half of the year and to 29/30 in Esfand.
 *
 * Counting for `maxOccurrences` always starts at starts_on, so the result is the same whichever
 * window is asked for.
 */
final class RecurrenceRule
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];
    private const MAX_ITERATIONS = 40000;

    /** @param list<int> $byWeekday */
    public function __construct(
        public readonly string $frequency,
        public readonly int $interval,
        public readonly array $byWeekday,
        public readonly ?int $byMonthDay,
        public readonly ?int $byMonth,
        public readonly string $startsOn,
        public readonly ?string $endsOn = null,
        public readonly ?int $maxOccurrences = null,
        public readonly string $calendar = 'gregorian',
    ) {
        if (!in_array($calendar, ['gregorian', 'jalali'], true)) {
            throw new InvalidArgumentException('Unknown calendar: '.$calendar);
        }
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('Unknown frequency: '.$frequency);
        }
        if ($interval < 1 || $interval > 366) {
            throw new InvalidArgumentException('Interval must be between 1 and 366.');
        }
        foreach ($byWeekday as $d) {
            if (!is_int($d) || $d < 1 || $d > 7) {
                throw new InvalidArgumentException('Weekdays must be ISO numbers 1–7.');
            }
        }
        if ($byMonthDay !== null && ($byMonthDay < 1 || $byMonthDay > 31)) {
            throw new InvalidArgumentException('Month day must be 1–31.');
        }
        if ($byMonth !== null && ($byMonth < 1 || $byMonth > 12)) {
            throw new InvalidArgumentException('Month must be 1–12.');
        }
    }

    /**
     * Occurrence dates (Y-m-d) inside [from, to], inclusive.
     *
     * @return list<string>
     */
    public function between(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($this->startsOn)->startOfDay();
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        if ($this->endsOn !== null) {
            $end = CarbonImmutable::parse($this->endsOn)->startOfDay();
            if ($end->lt($to)) $to = $end;
        }
        if ($to->lt($start) || $to->lt($from)) {
            return [];
        }

        $out = [];
        $count = 0;
        foreach ($this->candidates($start, $to) as $date) {
            $count++;
            if ($this->maxOccurrences !== null && $count > $this->maxOccurrences) {
                break;
            }
            if ($date->gte($from)) {
                $out[] = $date->toDateString();
            }
        }
        return $out;
    }

    /** Next occurrence on or after $date, or null when the series has ended. */
    public function next(string $date): ?string
    {
        $from = CarbonImmutable::parse($date);
        return $this->between($from->toDateString(), $from->addYears(5)->toDateString())[0] ?? null;
    }

    public static function fromJalali(int $jy, int $jm, int $jd): CarbonImmutable
    {
        [$gy, $gm, $gd] = LocalDate::jalaliToGregorian($jy, $jm, $jd);
        return CarbonImmutable::create($gy, $gm, $gd)->startOfDay();
    }

    public static function jalaliMonthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) return 31;
        if ($jm <= 11) return 30;
        // Esfand: the day before 1 Farvardin of the next year tells whether it has 29 or 30 days.
        $last = self::fromJalali($jy + 1, 1, 1)->subDay();
        return LocalDate::gregorianToJalali($last->year, $last->month, $last->day)[2];
    }

    /** @return \Generator<CarbonImmutable> all dates matching the rule from $start up to $to, in order */
    private function candidates(CarbonImmutable $start, CarbonImmutable $to): \Generator
    {
        $n = 0;
        switch ($this->frequency) {
            case 'daily':
                for ($d = $start; $d->lte($to) && $n++ < self::MAX_ITERATIONS; $d = $d->addDays($this->interval)) {
                    yield $d;
                }
                return;

            case 'weekly':
                $days = $this->byWeekday !== [] ? $this->byWeekday : [$start->dayOfWeekIso];
                $weekStart = $start->startOfWeek(CarbonImmutable::MONDAY);
                for ($d = $start; $d->lte($to) && $n++ < self::MAX_ITERATIONS; $d = $d->addDay()) {
                    $weekIndex = intdiv((int) $weekStart->diffInDays($d->startOfWeek(CarbonImmutable::MONDAY)), 7);
                    if ($weekIndex % $this->interval === 0 && in_array($d->dayOfWeekIso, $days, true)) {
                        yield $d;
                    }
                }
                return;

            case 'monthly':
                if ($this->calendar === 'jalali') {
                    [$jy, $jm, $jd] = LocalDate::gregorianToJalali($start->year, $start->month, $start->day);
                    $day = $this->byMonthDay ?? $jd;
                    for ($k = 0; $n++ < self::MAX_ITERATIONS; $k += $this->interval) {
                        $y = $jy + intdiv($jm - 1 + $k, 12);
                        $m = (($jm - 1 + $k) % 12) + 1;
                        $d = self::fromJalali($y, $m, min($day, self::jalaliMonthLength($y, $m)));
                        if ($d->gt($to)) return;
                        if ($d->gte($start)) yield $d;
                    }
                    return;
                }
                $day = $this->byMonthDay ?? $start->day;
                for ($k = 0; $n++ < self::MAX_ITERATIONS; $k += $this->interval) {
                    $month = $start->startOfMonth()->addMonthsNoOverflow($k);
                    $d = $month->setDay(min($day, $month->daysInMonth));
                    if ($d->gt($to)) return;
                    if ($d->gte($start)) yield $d;
                }
                return;

            case 'yearly':
                if ($this->calendar === 'jalali') {
                    [$jy, $jm, $jd] = LocalDate::gregorianToJalali($start->year, $start->month, $start->day);
                    $monthNo = $this->byMonth ?? $jm;
                    $day = $this->byMonthDay ?? $jd;
                    for ($k = 0; $n++ < self::MAX_ITERATIONS; $k += $this->interval) {
                        $d = self::fromJalali($jy + $k, $monthNo, min($day, self::jalaliMonthLength($jy + $k, $monthNo)));
                        if ($d->gt($to)) return;
                        if ($d->gte($start)) yield $d;
                    }
                    return;
                }
                $monthNo = $this->byMonth ?? $start->month;
                $day = $this->byMonthDay ?? $start->day;
                for ($k = 0; $n++ < self::MAX_ITERATIONS; $k += $this->interval) {
                    $month = CarbonImmutable::create($start->year + $k, $monthNo, 1);
                    $d = $month->setDay(min($day, $month->daysInMonth));
                    if ($d->gt($to)) return;
                    if ($d->gte($start)) yield $d;
                }
                return;
        }
    }
}
