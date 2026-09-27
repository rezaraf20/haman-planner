<?php
declare(strict_types=1);

namespace App\Support;

/** Timezone choices for the profile and onboarding forms. */
final class Timezones
{
    /** Shown first; the full IANA list follows. */
    public const COMMON = [
        'Asia/Tehran', 'Asia/Dubai', 'Europe/Istanbul', 'Europe/London', 'Europe/Berlin', 'Europe/Paris',
        'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'America/Toronto', 'Australia/Sydney', 'UTC',
    ];

    public static function valid(?string $tz): bool
    {
        return $tz !== null && in_array($tz, timezone_identifiers_list(), true);
    }

    /** @return array<string,string> identifier => label with current UTC offset */
    public static function options(): array
    {
        $out = [];
        foreach (array_merge(self::COMMON, timezone_identifiers_list()) as $tz) {
            if (isset($out[$tz])) continue;
            $offset = (new \DateTimeZone($tz))->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $sign = $offset < 0 ? '-' : '+';
            $out[$tz] = sprintf('(UTC%s%02d:%02d) %s', $sign, intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60), str_replace('_', ' ', $tz));
        }
        return $out;
    }
}
