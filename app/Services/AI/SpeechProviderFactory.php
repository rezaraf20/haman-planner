<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\SpeechProviderInterface;
use App\Services\AI\Drivers\GeminiSpeechProvider;
use App\Services\AI\Drivers\OpenAICompatibleSpeechProvider;
use InvalidArgumentException;

final class SpeechProviderFactory
{
    public static function make(): SpeechProviderInterface
    {
        $provider = strtolower((string) config('services.speech.provider', 'openai-compatible'));
        $key = (string) config('services.speech.api_key');

        if ($key === '') {
            throw new InvalidArgumentException('Speech API key is not configured.');
        }

        return match ($provider) {
            'gemini' => new GeminiSpeechProvider(
                $key,
                (string) config('services.speech.model', 'gemini-3.5-transcribe'),
                (string) config('services.speech.base_url', 'https://generativelanguage.googleapis.com'),
            ),
            'openai-compatible' => new OpenAICompatibleSpeechProvider(
                (string) config('services.speech.base_url', config('services.ai.base_url')),
                $key,
                (string) config('services.speech.model', 'whisper-1'),
            ),
            default => throw new InvalidArgumentException('Unsupported speech provider: '.$provider),
        };
    }
}
