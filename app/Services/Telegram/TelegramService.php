<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
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
        return 'https://api.telegram.org/bot' . $this->token() . '/' . $method;
    }

    /**
     * Guarantees Telegram's 2D InlineKeyboardButton format.
     *
     * Accepts proper rows ([[btn, btn], [btn]]), a single flat row ([btn, btn])
     * or mixed input where a bare button sits among rows. Invalid entries and
     * empty rows are dropped; an empty keyboard returns null (reply_markup omitted).
     */
    private function normalizeKeyboard(?array $keyboard): ?array
    {
        if ($keyboard === null || $keyboard === []) {
            return null;
        }

        $isButton = fn ($b): bool => is_array($b)
            && isset($b['text'])
            && (isset($b['callback_data']) || isset($b['url']));

        // A flat list of buttons = one row.
        if ($isButton($keyboard[array_key_first($keyboard)] ?? null) && count(array_filter($keyboard, $isButton)) === count($keyboard)) {
            return [array_values($keyboard)];
        }

        $rows = [];
        foreach ($keyboard as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['text'])) {
                $row = [$row];
            }
            $row = array_values(array_filter($row, $isButton));
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows === [] ? null : $rows;
    }

    private function replyMarkup(?array $keyboard): ?string
    {
        $rows = $this->normalizeKeyboard($keyboard);

        if ($rows === null) {
            return null;
        }

        return json_encode(
            ['inline_keyboard' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function assertTelegramSuccess(Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new RuntimeException(
                'Telegram ' . $operation . ' HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500)
            );
        }

        $json = $response->json();

        if (!is_array($json) || (($json['ok'] ?? false) !== true)) {
            throw new RuntimeException(
                'Telegram ' . $operation . ' failed: ' . mb_substr($response->body(), 0, 500)
            );
        }

        return $json;
    }

    public function sendMessage(string|int $chatId, string $text, ?array $keyboard = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096),
        ];

        $markup = $this->replyMarkup($keyboard);
        if ($markup !== null) {
            $payload['reply_markup'] = $markup;
        }

        $response = Http::timeout(8)->post($this->api('sendMessage'), $payload);

        return $this->assertTelegramSuccess($response, 'sendMessage');
    }

    public function editMessage(string|int $chatId, int $messageId, string $text, ?array $keyboard = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => mb_substr($text, 0, 4096),
        ];

        $markup = $this->replyMarkup($keyboard);
        if ($markup !== null) {
            $payload['reply_markup'] = $markup;
        }

        $response = Http::timeout(8)->post($this->api('editMessageText'), $payload);

        return $this->assertTelegramSuccess($response, 'editMessageText');
    }

    public function answerCallback(string $callbackId, ?string $text = null): array
    {
        $payload = ['callback_query_id' => $callbackId];

        if ($text !== null) {
            $payload['text'] = mb_substr($text, 0, 200);
        }

        $response = Http::timeout(8)->post($this->api('answerCallbackQuery'), $payload);

        return $this->assertTelegramSuccess($response, 'answerCallbackQuery');
    }

    public function getFilePath(string $fileId): string
    {
        $response = Http::timeout(8)->get($this->api('getFile'), ['file_id' => $fileId]);

        $json = $this->assertTelegramSuccess($response, 'getFile');

        $path = (string) ($json['result']['file_path'] ?? '');

        if ($path === '') {
            throw new RuntimeException('Telegram returned no file path.');
        }

        return $path;
    }

    public function downloadFile(string $filePath, string $destination): void
    {
        $response = Http::timeout(60)->get(
            'https://api.telegram.org/file/bot' . $this->token() . '/' . $filePath
        );

        if ($response->failed()) {
            throw new RuntimeException('Telegram file download failed: ' . $response->status());
        }

        if (file_put_contents($destination, $response->body()) === false) {
            throw new RuntimeException('Unable to write Telegram audio file.');
        }
    }
}
