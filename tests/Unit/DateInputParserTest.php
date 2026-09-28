<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DateInputParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateInputParserTest extends TestCase
{
    /** Monday 2026-09-28 16:20 Tehran = 1405-07-06 */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 9, 28, 16, 20, 0, 'Asia/Tehran');
    }

    public static function valid(): array
    {
        return [
            'iso' => ['2026-09-30 14:30', '2026-09-30 14:30'],
            'jalali dashes' => ['1405-07-08 14:30', '2026-09-30 14:30'],
            'jalali slashes persian digits' => ['۱۴۰۵/۰۷/۰۸ ۱۴:۳۰', '2026-09-30 14:30'],
            'time first (as shown in RTL chats)' => ['14:30 1405-07-08', '2026-09-30 14:30'],
            'year last (as shown in RTL chats)' => ['08-07-1405 14:30', '2026-09-30 14:30'],
            'both reversed' => ['۱۴:۳۰ ۰۸-۰۷-۱۴۰۵', '2026-09-30 14:30'],
            'direction marks' => ["\u{200F}1405/07/08\u{200F} 14:30\u{200E}", '2026-09-30 14:30'],
            'saat word' => ['۱۴۰۵/۷/۸ ساعت ۱۴:۳۰', '2026-09-30 14:30'],
            'T separator' => ['2026-09-30T09:05', '2026-09-30 09:05'],
            'dash between' => ['1405/07/08 - 14:30', '2026-09-30 14:30'],
            'dotted date' => ['۱۴۰۵٫۰۷٫۰۸', '2026-09-30 09:00'],
            'seconds' => ['2026-09-30 14:30:00', '2026-09-30 14:30'],
            'time only' => ['14:30', '2026-09-28 14:30'],
            'time only persian' => ['۱۴:۳۰', '2026-09-28 14:30'],
            'dotted time' => ['14.30', '2026-09-28 14:30'],
            'hour only' => ['ساعت ۱۰', '2026-09-28 10:00'],
            'bare hour' => ['18', '2026-09-28 18:00'],
            'afternoon' => ['۵ عصر', '2026-09-28 17:00'],
            'morning' => ['فردا ۸ صبح', '2026-09-29 08:00'],
            'pm' => ['tomorrow 5pm', '2026-09-29 17:00'],
            'noon' => ['فردا ظهر', '2026-09-29 12:00'],
            'tomorrow' => ['فردا 10:00', '2026-09-29 10:00'],
            'tomorrow saat' => ['فردا ساعت ۱۰', '2026-09-29 10:00'],
            'day after tomorrow' => ['پس‌فردا ۹:۳۰', '2026-09-30 09:30'],
            'day after tomorrow spaced' => ['پس فردا', '2026-09-30 09:00'],
            'today word' => ['امروز ساعت 20:15', '2026-09-28 20:15'],
            'yesterday' => ['دیروز 10:00', '2026-09-27 10:00'],
            'month name' => ['۸ مهر ساعت ۱۴:۳۰', '2026-09-30 14:30'],
            'month name + pm hour' => ['۸ مهر ۵ عصر', '2026-09-30 17:00'],
            'saat + morning' => ['فردا ساعت ۸:۳۰ صبح', '2026-09-29 08:30'],
            '12 am' => ['12 am', '2026-09-28 00:00'],
            'month name with year' => ['۸ مهر ۱۴۰۵', '2026-09-30 09:00'],
            'english month' => ['Oct 7 10:00', '2026-10-07 10:00'],
            'month/day fa (jalali)' => ['7/8', '2026-09-30 09:00'],
            'weekday' => ['چهارشنبه ۱۰:۰۰', '2026-09-30 10:00'],
            'weekday same day → next week' => ['دوشنبه', '2026-10-05 09:00'],
            'weekday zwnj' => ["سه\u{200C}شنبه 11:00", '2026-09-29 11:00'],
            'in hours' => ['۲ ساعت دیگه', '2026-09-28 18:20'],
            'in minutes' => ['30 دقیقه دیگر', '2026-09-28 16:50'],
            'in english' => ['in 2 hours', '2026-09-28 18:20'],
            'now' => ['الان', '2026-09-28 16:20'],
            'arabic digits' => ['١٤٠٥/٠٧/٠٨ ١٤:٣٠', '2026-09-30 14:30'],
            'esfand 30 in a leap year' => ['1403-12-30', '2025-03-20 09:00'],
        ];
    }

    #[DataProvider('valid')]
    public function test_accepts_common_shapes(string $input, string $expected): void
    {
        $d = DateInputParser::parse($input, 'Asia/Tehran', true, 'fa', $this->now());
        $this->assertNotNull($d, $input);
        $this->assertSame($expected, $d->format('Y-m-d H:i'), $input);
        $this->assertSame('Asia/Tehran', $d->getTimezone()->getName());
    }

    public static function invalid(): array
    {
        return [[''], ['hello'], ['25:00'], ['14:75'], ['1405-13-01'], ['1405-07-31'], ['1404-12-30'], ['2026-02-30'], ['1405-07-08 25:10'], ['99'], ['فردا hello']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_nonsense(string $input): void
    {
        $this->assertNull(DateInputParser::parse($input, 'Asia/Tehran', true, 'fa', $this->now()), $input);
    }

    public function test_date_only_and_english_month_day(): void
    {
        $this->assertSame('2026-09-30 00:00', DateInputParser::parse('1405/7/8', 'Asia/Tehran', false, 'fa', $this->now())->format('Y-m-d H:i'));
        $this->assertSame('2026-10-07 09:00', DateInputParser::parse('10/7', 'Europe/Berlin', true, 'en', $this->now())->format('Y-m-d H:i'));
        $this->assertSame('Europe/Berlin', DateInputParser::parse('10/7', 'Europe/Berlin', true, 'en', $this->now())->getTimezone()->getName());
    }
}
