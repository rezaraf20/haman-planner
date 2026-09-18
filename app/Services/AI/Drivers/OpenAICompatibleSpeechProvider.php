<?php
declare(strict_types=1);

namespace App\Services\AI\Drivers;

use App\Contracts\SpeechProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAICompatibleSpeechProvider implements SpeechProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model = 'whisper-1',
    ) {}

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (! is_file($audioPath)) {
            throw new RuntimeException('Audio file not found.');
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->attach('file', fopen($audioPath, 'rb'), basename($audioPath))
            ->post(rtrim($this->baseUrl, '/').'/audio/transcriptions', array_merge([
                'model' => $this->model,
                'response_format' => 'json',
            ], $options));

        if ($response->failed()) {
            throw new RuntimeException('Speech provider request failed: '.$response->status());
        }

        $data = $response->json();

        return [
            'text' => trim((string) ($data['text'] ?? '')),
            'raw' => $data,
        ];
    }
}
