<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Services\AI\Drivers\OpenAICompatibleProvider;
use InvalidArgumentException;

final class AIProviderFactory
{
    public static function make(): AIProviderInterface
    {
        $provider = strtolower(trim((string) config('services.ai.provider', 'openai')));
        $key = (string) config('services.ai.api_key');
        if ($key === '') throw new InvalidArgumentException('AI_API_KEY is not configured.');

        $defaults = [
            'openai' => 'https://api.openai.com/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'groq' => 'https://api.groq.com/openai/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'xai' => 'https://api.x.ai/v1',
        ];
        if (!array_key_exists($provider, $defaults)) {
            throw new InvalidArgumentException('Unsupported AI_PROVIDER: '.$provider);
        }

        $baseUrl = trim((string) config('services.ai.base_url', ''));
        if ($baseUrl === '' || $baseUrl === 'https://api.openai.com/v1') {
            $baseUrl = $defaults[$provider];
        }
        $model = (string) config('services.ai.model', 'gpt-4o-mini');
        if ($model === '') throw new InvalidArgumentException('AI_MODEL is not configured.');

        return new OpenAICompatibleProvider($baseUrl, $key, $model);
    }
}
