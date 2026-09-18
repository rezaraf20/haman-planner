<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\SpeechProviderInterface;
use App\Services\AI\Drivers\OpenAICompatibleSpeechProvider;
use InvalidArgumentException;

final class SpeechProviderFactory
{
    public static function make(): SpeechProviderInterface
    {
        $key = (string) config('services.speech.api_key');
        if ($key === '') {
            throw new InvalidArgumentException('SPEECH_API_KEY is not configured.');
        }

        return new OpenAICompatibleSpeechProvider(
            (string) config('services.speech.base_url', config('services.ai.base_url')),
            $key,
            (string) config('services.speech.model', 'whisper-1'),
        );
    }
}
