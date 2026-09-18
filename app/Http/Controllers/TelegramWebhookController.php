<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\PlannerIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TelegramWebhookController extends Controller
{
    public function __construct(private readonly PlannerIntentService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $update = $request->all();
        $message = $update['message'] ?? [];
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '' || $chatId === null) {
            return response()->json(['ok' => true]);
        }

        try {
            $this->service->handleText($text, $chatId);
        } catch (\Throwable $e) {
            Log::error('Telegram planner command failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
