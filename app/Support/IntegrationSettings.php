<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Third-party integration credentials edited in Admin → Integrations (same rules as
 * PaymentSettings: panel values override .env, secrets are encrypted and shown masked).
 */
final class IntegrationSettings
{
    private const CACHE_KEY = 'haman:integration_settings:v1';

    /** field => [config path, is secret] */
    public const FIELDS = [
        'google_client_id' => ['services.google_calendar.client_id', false],
        'google_client_secret' => ['services.google_calendar.client_secret', true],
    ];

    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return AppSetting::query()->whereIn('key', array_map(fn ($f) => 'int_'.$f, array_keys(self::FIELDS)))
                    ->pluck('value', 'key')->mapWithKeys(fn ($v, $k) => [substr($k, 4) => $v])->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function get(string $field): ?string
    {
        [$path, $secret] = self::FIELDS[$field];
        $raw = self::stored()[$field] ?? null;
        if ($raw === null || $raw === '') {
            $v = config($path);
            return is_string($v) && $v !== '' ? $v : null;
        }
        if ($secret) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                $v = config($path);
                return is_string($v) && $v !== '' ? $v : null;
            }
        }
        return $raw;
    }

    public static function source(string $field): string
    {
        $raw = self::stored()[$field] ?? null;
        if ($raw !== null && $raw !== '') return 'panel';
        return filled(config(self::FIELDS[$field][0])) ? 'env' : 'none';
    }

    public static function masked(string $field): ?string
    {
        $v = (string) self::get($field);
        if ($v === '') return null;
        return self::FIELDS[$field][1] ? '••••'.mb_substr($v, -4) : $v;
    }

    /** '' keeps the saved value, null clears it (back to .env). */
    public static function put(array $values): void
    {
        foreach ($values as $field => $value) {
            if (!isset(self::FIELDS[$field])) continue;
            $key = 'int_'.$field;
            if ($value === null) {
                AppSetting::query()->where('key', $key)->delete();
            } elseif (trim((string) $value) !== '') {
                $v = trim((string) $value);
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => self::FIELDS[$field][1] ? Crypt::encryptString($v) : $v]);
            }
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<string> */
    public static function secrets(): array
    {
        $out = [];
        foreach (self::FIELDS as $f => [, $secret]) {
            if ($secret && ($v = self::get($f))) $out[] = $v;
        }
        return $out;
    }
}
