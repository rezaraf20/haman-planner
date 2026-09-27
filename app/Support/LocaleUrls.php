<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

/** Builds language-switcher and hreflang URLs for the current page. */
final class LocaleUrls
{
    /** Public pages that exist once per language: fa route name => en route name. */
    public const MARKETING = [
        'marketing.home' => 'marketing.home.en',
        'marketing.pricing' => 'marketing.pricing.en',
        'marketing.privacy' => 'marketing.privacy.en',
        'marketing.terms' => 'marketing.terms.en',
    ];

    public static function counterpart(string $routeName, string $locale): ?string
    {
        $fa = array_search($routeName, self::MARKETING, true) ?: (isset(self::MARKETING[$routeName]) ? $routeName : null);
        if ($fa === null) {
            return null;
        }
        return route($locale === 'fa' ? $fa : self::MARKETING[$fa]);
    }

    /** URL that shows the current page in $locale, keeping path and query string. */
    public static function switchTo(string $locale): string
    {
        $name = Route::currentRouteName();
        if ($name && ($url = self::counterpart($name, $locale))) {
            return $url;
        }
        return route('locale.switch', ['locale' => $locale, 'to' => request()->getRequestUri()]);
    }
}
