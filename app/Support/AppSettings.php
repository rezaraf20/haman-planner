<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable platform settings (stored in app_settings, cached).
 * Falls back to defaults when the table does not exist yet (before migrating).
 */
final class AppSettings
{
    private const CACHE_KEY = 'haman:app_settings:v1';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'app_name' => (string) config('app.name', 'Haman Planner'),
            'app_tagline' => '',      // Persian tagline (empty = translated default)
            'app_tagline_en' => '',   // English tagline (empty = translated default)
            'logo' => null,
            'registration_enabled' => (bool) config('services.haman_planner.registration', true),
            'signup_trial' => true,   // new accounts get the first public plan trial (Admin → Settings)
            'support_enabled' => true,
            'support_note' => '',
            'announcement' => '',
            'font_fa' => 'vazirmatn',   // vazirmatn | custom (uploaded, e.g. IRANSans)
            'font_fa_name' => '',       // display name of the uploaded font
            'font_fa_version' => '',    // JSON of content hashes of the uploaded files
            'font_en' => 'poppins',     // poppins | persian (use the Persian font's Latin glyphs)
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                // Large values (uploaded fonts, page content) are read on their own, not cached here.
                return AppSetting::query()->where('key', 'not like', 'font_file_%')->where('key', '!=', 'landing_content')
                    ->pluck('value', 'key')->all();
            } catch (\Throwable) {
                return [];
            }
        });
        $out = self::defaults();
        foreach ($stored as $k => $v) {
            if (!array_key_exists($k, $out)) continue;
            $out[$k] = is_bool($out[$k]) ? $v === '1' : $v;
        }
        $out['tagline'] = self::tagline($out);
        return $out;
    }

    /** Tagline for the current interface language; falls back to the translated default. */
    private static function tagline(array $settings): string
    {
        $value = trim((string) (app()->getLocale() === 'en' ? $settings['app_tagline_en'] : $settings['app_tagline']));
        return $value !== '' ? $value : (string) __('auth.default_tagline');
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /** @param array<string,mixed> $values */
    public static function put(array $values): void
    {
        $defaults = self::defaults();
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, $defaults)) continue;
            $value = is_bool($defaults[$k]) ? ($v ? '1' : '0') : ($v === null ? null : (string) $v);
            AppSetting::query()->updateOrCreate(['key' => $k], ['value' => $value]);
        }
        Cache::forget(self::CACHE_KEY);
    }
}
