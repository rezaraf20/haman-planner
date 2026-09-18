<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\PlannerIntentService;
use App\Services\AI\SpeechProviderFactory;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly PlannerIntentService $service,
        private readonly TelegramService $telegram,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '' && app()->environment('production')) {
            return response()->json(['ok' => false], 503);
        }
        if ($secret !== '') {
            $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($provided === '' || !hash_equals($secret, $provided)) {
                return response()->json(['ok' => false], 401);
            }
        }

        $message = $request->input('message', []);
        $chatId = $message['chat']['id'] ?? null;

        if ($chatId === null) {
            return response()->json(['ok' => true]);
        }

        try {
            $text = trim((string) ($message['text'] ?? ''));

            if ($text !== '') {
                $this->service->handleText($text, $chatId);
                return response()->json(['ok' => true]);
            }

            $voice = $message['voice'] ?? null;
            if (is_array($voice) && ! empty($voice['file_id'])) {
                $this->handleVoice((string) $voice['file_id'], $chatId);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram planner update failed', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
            ]);

            try {
                $this->telegram->sendMessage($chatId, 'خطا در پردازش درخواست. لطفاً دوباره تلاش کنید.');
            } catch (\Throwable) {
                // Do not turn Telegram delivery errors into webhook failures.
            }
        }

        return response()->json(['ok' => true]);
    }

    private function handleVoice(string $fileId, string|int $chatId): void
    {
        $filePath = $this->telegram->getFilePath($fileId);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'ogg');
        if (! in_array($extension, ['ogg', 'oga', 'mp3', 'm4a', 'wav', 'webm'], true)) {
            $this->telegram->sendMessage($chatId, 'فرمت فایل صوتی پشتیبانی نمی‌شود.');
            return;
        }
        $temporaryPath = storage_path('app/'.Str::uuid().'.'.$extension);

        try {
            $this->telegram->downloadFile($filePath, $temporaryPath);
            $maxBytes = 20 * 1024 * 1024;
            if (! is_file($temporaryPath) || filesize($temporaryPath) === false || filesize($temporaryPath) > $maxBytes) {
                $this->telegram->sendMessage($chatId, 'حجم فایل صوتی بیش از حد مجاز است.');
                return;
            }

            $result = SpeechProviderFactory::make()->transcribe($temporaryPath);
            $text = trim((string) ($result['text'] ?? ''));

            if ($text === '') {
                $this->telegram->sendMessage($chatId, 'صدای شما قابل تشخیص نبود.');
                return;
            }

            $this->telegram->sendMessage($chatId, "متوجه شدم: {$text}");
            $this->service->handleText($text, $chatId);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
