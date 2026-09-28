<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Payment provider settings edited in Admin → Payments → Settings.
 *
 * Values saved in the panel take precedence; anything left empty falls back to .env
 * (config/billing.php). Secrets (merchant id, API keys) are stored encrypted with APP_KEY
 * and are never sent back to the browser — the panel only shows the last 4 characters.
 */
final class PaymentSettings
{
    private const CACHE_KEY = 'haman:payment_settings:v1';

    /** field => [config path, is secret, is boolean] */
    public const FIELDS = [
        'zarinpal_enabled' => ['billing.providers.zarinpal.enabled', false, true],
        'zarinpal_merchant_id' => ['billing.providers.zarinpal.merchant_id', true, false],
        'zarinpal_sandbox' => ['billing.providers.zarinpal.sandbox', false, true],
        'stripe_enabled' => ['billing.providers.stripe.enabled', false, true],
        'stripe_secret' => ['billing.providers.stripe.secret', true, false],
        'stripe_webhook_secret' => ['billing.providers.stripe.webhook_secret', true, false],
    ];

    /** @return array<string,?string> raw stored values (secrets still encrypted) */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return AppSetting::query()->whereIn('key', array_map(fn ($f) => 'pay_'.$f, array_keys(self::FIELDS)))
                    ->pluck('value', 'key')->mapWithKeys(fn ($v, $k) => [substr($k, 4) => $v])->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** Effective value: panel value if saved, otherwise the .env/config value. */
    public static function get(string $field): mixed
    {
        [$path, $secret, $bool] = self::FIELDS[$field];
        $raw = self::stored()[$field] ?? null;
        if ($raw === null || $raw === '') {
            return config($path);
        }
        if ($bool) {
            return $raw === '1';
        }
        if ($secret) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                return config($path); // APP_KEY changed: ignore the unreadable value
            }
        }
        return $raw;
    }

    /** Where the effective value comes from: 'panel', 'env' or 'none'. */
    public static function source(string $field): string
    {
        $raw = self::stored()[$field] ?? null;
        if ($raw !== null && $raw !== '') {
            return 'panel';
        }
        return filled(config(self::FIELDS[$field][0])) ? 'env' : 'none';
    }

    /** "••••1234" for display; never the full secret. */
    public static function masked(string $field): ?string
    {
        $value = (string) self::get($field);
        return $value === '' ? null : '••••'.mb_substr($value, -4);
    }

    /**
     * @param array<string,mixed> $values booleans for flags; for secrets a non-empty string replaces the
     *                                    saved value, '' keeps it, and null clears it (back to .env)
     */
    public static function put(array $values): void
    {
        foreach ($values as $field => $value) {
            if (!isset(self::FIELDS[$field])) {
                continue;
            }
            [, $secret, $bool] = self::FIELDS[$field];
            $key = 'pay_'.$field;
            if ($bool) {
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value ? '1' : '0']);
            } elseif ($value === null) {
                AppSetting::query()->where('key', $key)->delete();
            } elseif (trim((string) $value) !== '') {
                $v = trim((string) $value);
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $secret ? Crypt::encryptString($v) : $v]);
            }
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<string> effective secret values, for masking logs */
    public static function secrets(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [, $secret]) {
            if ($secret && is_string($v = self::get($field)) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }
}
