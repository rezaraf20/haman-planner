<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable plan. Names/descriptions are stored per locale; prices per currency and
 * billing interval in the currency's smallest unit; limits (null = unlimited) and feature flags.
 */
final class Plan extends Model
{
    public const INTERVALS = ['monthly', 'yearly'];

    protected $fillable = ['code', 'name', 'description', 'prices', 'limits', 'features', 'trial_days', 'is_default', 'is_active', 'is_public', 'sort_order'];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'prices' => 'array',
        'limits' => 'array',
        'features' => 'array',
        'trial_days' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }

    public function localizedName(?string $locale = null): string
    {
        $names = is_array($this->name) ? $this->name : [];
        return (string) ($names[$locale ?? app()->getLocale()] ?? $names['en'] ?? $names['fa'] ?? $this->code);
    }

    public function localizedDescription(?string $locale = null): string
    {
        $d = is_array($this->description) ? $this->description : [];
        return (string) ($d[$locale ?? app()->getLocale()] ?? $d['en'] ?? $d['fa'] ?? '');
    }

    /** Price in minor units, or null when the plan is not sold in that currency/interval. */
    public function price(string $currency, string $interval): ?int
    {
        $v = $this->prices[$currency][$interval] ?? null;
        return is_numeric($v) ? (int) $v : null;
    }

    public function isFree(): bool
    {
        foreach ((array) $this->prices as $intervals) {
            foreach ((array) $intervals as $v) {
                if ((int) $v > 0) return false;
            }
        }
        return true;
    }

    public function limit(string $metric): ?int
    {
        $limits = is_array($this->limits) ? $this->limits : [];
        if (!array_key_exists($metric, $limits) || $limits[$metric] === null || $limits[$metric] === '') {
            return null;
        }
        return max(0, (int) $limits[$metric]);
    }

    public function hasFeature(string $feature): bool
    {
        $features = is_array($this->features) ? $this->features : [];
        return (bool) ($features[$feature] ?? false);
    }
}
