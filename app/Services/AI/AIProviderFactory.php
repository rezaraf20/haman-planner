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
        $key = (string) config('services.ai.api_key');
        if ($key === '') throw new InvalidArgumentException('AI_API_KEY is not configured.');

        return new OpenAICompatibleProvider(
            (string) config('services.ai.base_url', 'https://api.openai.com/v1'),
            $key,
            (string) config('services.ai.model', 'gpt-4o-mini')
        );
    }
}
