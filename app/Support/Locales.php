<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/** Central list of supported interface languages and their text direction. */
final class Locales
{
    public const DEFAULT = 'fa';
    public const SUPPORTED = ['fa', 'en'];
    public const RTL = ['fa'];
    public const COOKIE = 'hp_locale';

    public static function normalize(?string $locale): ?string
    {
        $locale = strtolower(substr(trim((string) $locale), 0, 2));
        return in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }

    public static function dir(?string $locale = null): string
    {
        return in_array($locale ?? app()->getLocale(), self::RTL, true) ? 'rtl' : 'ltr';
    }

    public static function other(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'fa' ? 'en' : 'fa';
    }

    /** Native language name, shown in the switcher. */
    public static function label(string $locale): string
    {
        return ['fa' => 'فارسی', 'en' => 'English'][$locale] ?? $locale;
    }

    /** Best supported match from the browser's Accept-Language header, or null. */
    public static function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            if ($match = self::normalize($language)) {
                return $match;
            }
        }
        return null;
    }
}
