<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Builds the AI client. Connections added in Admin → AI take precedence (priority order, with
 * automatic fallback); without any, the AI_* values from .env are used exactly as before.
 */
final class AIProviderFactory
{
    private const ENV_DEFAULTS = [
        'openai' => 'https://api.openai.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'groq' => 'https://api.groq.com/openai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'xai' => 'https://api.x.ai/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
    ];

    public static function make(): AIProviderInterface
    {
        $candidates = self::candidates();
        if ($candidates === []) {
            throw new InvalidArgumentException('AI is not configured (Admin → AI or AI_API_KEY).');
        }
        return new ManagedAIProvider($candidates);
    }

    /** Whether any AI connection is configured (used to decide if AI features are offered). */
    public static function available(): bool
    {
        return self::candidates() !== [];
    }

    /** Provider/model that answered the most recent call in this process. */
    public static function lastUsed(): array
    {
        return ManagedAIProvider::lastUsed() ?? ['provider' => (string) config('services.ai.provider', 'configured'), 'model' => (string) config('services.ai.model', 'configured'), 'id' => null];
    }

    /** @return list<array{id:?int,label:string,base:string,key:string,model:string,provider:?AiProvider}> */
    private static function candidates(): array
    {
        $out = [];
        if (self::tableReady()) {
            foreach (AiProvider::query()->active()->get() as $p) {
                $base = $p->baseUrl();
                if ($base === '' || $p->model === '') {
                    continue;
                }
                try {
                    $key = (string) $p->api_key;
                } catch (\Throwable) {
                    continue; // APP_KEY changed: the stored key can no longer be read
                }
                if ($key !== '') {
                    $out[] = ['id' => $p->id, 'label' => $p->name, 'base' => $base, 'key' => $key, 'model' => $p->model, 'provider' => $p];
                }
            }
        }
        if ($out !== []) {
            return $out;
        }
        $key = (string) config('services.ai.api_key');
        $provider = strtolower(trim((string) config('services.ai.provider', 'openai'))) ?: 'openai';
        if ($key === '' || !array_key_exists($provider, self::ENV_DEFAULTS)) {
            return [];
        }
        $base = trim((string) config('services.ai.base_url', ''));
        if ($base === '' || $base === 'https://api.openai.com/v1') {
            $base = self::ENV_DEFAULTS[$provider];
        }
        $model = (string) config('services.ai.model', 'gpt-4o-mini');
        return $model === '' ? [] : [['id' => null, 'label' => $provider.' (.env)', 'base' => $base, 'key' => $key, 'model' => $model, 'provider' => null]];
    }

    private static function tableReady(): bool
    {
        static $ready = null;
        if ($ready === true) {
            return true;
        }
        try {
            return $ready = Schema::hasTable('ai_providers');
        } catch (\Throwable) {
            return false;
        }
    }
}
