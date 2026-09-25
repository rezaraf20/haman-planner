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
            'app_tagline' => 'برنامه‌ریزی شخصی و کاری شما',
            'logo' => null,
            'registration_enabled' => (bool) config('services.haman_planner.registration', true),
            'support_enabled' => true,
            'support_note' => '',
            'announcement' => '',
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return AppSetting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                return [];
            }
        });
        $out = self::defaults();
        foreach ($stored as $k => $v) {
            if (!array_key_exists($k, $out)) continue;
            $out[$k] = is_bool($out[$k]) ? $v === '1' : $v;
        }
        return $out;
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
