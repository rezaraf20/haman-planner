<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramService
{
    private function token(): string
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return $token;
    }

    private function api(string $method): string
    {
        return 'https://api.telegram.org/bot'.$this->token().'/'.$method;
    }

    public function sendMessage(string|int $chatId, string $text): array
    {
        $response = Http::post($this->api('sendMessage'), [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Telegram request failed: '.$response->status());
        }

        return $response->json();
    }

    public function getFilePath(string $fileId): string
    {
        $response = Http::get($this->api('getFile'), ['file_id' => $fileId]);

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException('Telegram getFile request failed.');
        }

        $path = (string) $response->json('result.file_path');
        if ($path === '') {
            throw new RuntimeException('Telegram returned no file path.');
        }

        return $path;
    }

    public function downloadFile(string $filePath, string $destination): void
    {
        $response = Http::timeout(60)->get(
            'https://api.telegram.org/file/bot'.$this->token().'/'.$filePath
        );

        if ($response->failed()) {
            throw new RuntimeException('Telegram file download failed: '.$response->status());
        }

        if (file_put_contents($destination, $response->body()) === false) {
            throw new RuntimeException('Unable to write Telegram audio file.');
        }
    }
}
