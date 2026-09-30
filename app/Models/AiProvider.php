<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An AI API connection managed in Admin → AI. All drivers speak the OpenAI chat-completions protocol. */
final class AiProvider extends Model
{
    public const DRIVERS = [
        'openai' => 'https://api.openai.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'xai' => 'https://api.x.ai/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'custom' => null,
    ];

    /** Drivers whose API reports the remaining account credit. */
    public const BALANCE_DRIVERS = ['openrouter', 'deepseek'];

    protected $fillable = [
        'name', 'driver', 'base_url', 'api_key', 'model', 'is_active', 'priority', 'monthly_token_budget',
        'input_price_per_million', 'output_price_per_million', 'balance', 'balance_checked_at',
        'last_success_at', 'last_error_at', 'last_error',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'monthly_token_budget' => 'integer',
        'input_price_per_million' => 'float',
        'output_price_per_million' => 'float',
        'balance' => 'array',
        'balance_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function usage(): HasMany { return $this->hasMany(AiUsage::class); }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('priority')->orderBy('id');
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->base_url ?: (self::DRIVERS[$this->driver] ?? '')), '/');
    }

    public function tokensThisMonth(): int
    {
        return (int) AiUsage::query()->where('ai_provider_id', $this->id)->where('created_at', '>=', now()->startOfMonth())->sum('total_tokens');
    }

    public function remainingBudget(): ?int
    {
        return $this->monthly_token_budget === null ? null : max(0, $this->monthly_token_budget - $this->tokensThisMonth());
    }

    public function overBudget(): bool
    {
        return $this->monthly_token_budget !== null && $this->tokensThisMonth() >= $this->monthly_token_budget;
    }

    public function supportsBalance(): bool
    {
        return in_array($this->driver, self::BALANCE_DRIVERS, true);
    }

    /** Estimated cost in USD for token counts (null when no prices are set). */
    public function cost(int $prompt, int $completion): ?float
    {
        if ($this->input_price_per_million === null && $this->output_price_per_million === null) {
            return null;
        }
        return ($prompt * (float) $this->input_price_per_million + $completion * (float) $this->output_price_per_million) / 1_000_000;
    }

    public function maskedKey(): string
    {
        $k = (string) $this->api_key;
        return $k === '' ? '' : '••••'.mb_substr($k, -4);
    }
}
