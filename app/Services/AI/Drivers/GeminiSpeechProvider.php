<?php
declare(strict_types=1);

namespace App\Services\AI\Drivers;

use App\Contracts\SpeechProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GeminiSpeechProvider implements SpeechProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-3.5-transcribe',
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com',
    ) {}

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (! is_file($audioPath)) {
            throw new RuntimeException('Audio file not found.');
        }

        $mimeType = $this->mimeType($audioPath);
        $bytes = filesize($audioPath);

        if ($bytes === false) {
            throw new RuntimeException('Unable to determine audio file size.');
        }

        $start = Http::timeout(30)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $bytes,
                'X-Goog-Upload-Header-Content-Type' => $mimeType,
            ])
            ->post(rtrim($this->baseUrl, '/').'/upload/v1beta/files', [
                'file' => [
                    'display_name' => basename($audioPath),
                ],
            ]);

        if ($start->failed()) {
            throw new RuntimeException('Gemini upload initialization failed: '.$start->status());
        }

        $uploadUrl = (string) $start->header('X-Goog-Upload-URL');
        if ($uploadUrl === '') {
            throw new RuntimeException('Gemini did not return an upload URL.');
        }

        $contents = file_get_contents($audioPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read audio file.');
        }

        $upload = Http::timeout(120)
            ->withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
                'Content-Length' => (string) $bytes,
            ])
            ->withBody($contents, $mimeType)
            ->post($uploadUrl);

        if ($upload->failed()) {
            throw new RuntimeException('Gemini audio upload failed: '.$upload->status());
        }

        $fileUri = (string) $upload->json('file.uri');
        $uploadedFileName = (string) $upload->json('file.name');

        if ($fileUri === '') {
            throw new RuntimeException('Gemini upload returned no file URI.');
        }

        try {
            $interaction = Http::timeout(120)
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post(rtrim($this->baseUrl, '/').'/v1beta/interactions', [
                    'model' => $this->model,
                    'input' => [[
                        'type' => 'audio',
                        'uri' => $fileUri,
                        'mime_type' => $mimeType,
                    ]],
                    'generation_config' => [
                        'transcription_config' => [
                            'language_codes' => ['fa-IR'],
                            'mode' => 'smart',
                        ],
                    ],
                ]);

            if ($interaction->failed()) {
                throw new RuntimeException('Gemini transcription request failed: '.$interaction->status());
            }

            $data = $interaction->json();
            $text = $this->extractText($data);

            return [
                'text' => trim($text),
                'raw' => $data,
            ];
        } finally {
            if ($uploadedFileName !== '') {
                Http::timeout(15)
                    ->withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->delete(rtrim($this->baseUrl, '/').'/v1beta/'.$uploadedFileName);
            }
        }
    }

    private function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'ogg', 'oga' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };
    }

    private function extractText(array $data): string
    {
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }

        $texts = [];

        foreach (($data['steps'] ?? []) as $step) {
            foreach (($step['content'] ?? []) as $content) {
                if (is_string($content['text'] ?? null)) {
                    $texts[] = $content['text'];
                }
            }
        }

        foreach (($data['outputs'] ?? []) as $output) {
            if (is_string($output['text'] ?? null)) {
                $texts[] = $output['text'];
            }
        }

        return trim(implode("\n", $texts));
    }
}
