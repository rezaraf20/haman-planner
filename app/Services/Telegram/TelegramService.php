<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramService
{
    public function sendMessage(string|int $chatId, string $text): array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $response = Http::post(
            'https://api.telegram.org/bot'.$token.'/sendMessage',
            ['chat_id' => $chatId, 'text' => $text]
        );

        if ($response->failed()) {
            throw new RuntimeException('Telegram request failed: '.$response->status());
        }

        return $response->json();
    }
}
