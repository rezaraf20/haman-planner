<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Reminder;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReminderService
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function due(?Carbon $until = null)
    {
        return Reminder::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', $until ?? now())
            ->orderBy('scheduled_at')
            ->get();
    }

    public function dispatchDue(?Carbon $until = null): int
    {
        $count = 0;

        foreach ($this->due($until) as $reminder) {
            $sent = $this->dispatch($reminder);
            if ($sent) {
                $count++;
            }
        }

        return $count;
    }

    public function dispatch(Reminder $reminder): bool
    {
        $payload = is_array($reminder->payload) ? $reminder->payload : [];
        $chatId = $payload['chat_id'] ?? null;

        if ($chatId === null) {
            $reminder->update([
                'status' => 'failed',
                'payload' => array_merge($payload, ['error' => 'chat_id is missing']),
            ]);
            return false;
        }

        $text = (string) ($payload['message'] ?? $this->defaultMessage($reminder));
        $now = now();

        return DB::transaction(function () use ($reminder, $chatId, $text, $now): bool {
            $locked = Reminder::query()->lockForUpdate()->find($reminder->id);
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $this->telegram->sendMessage($chatId, $text);

            $locked->update([
                'status' => 'sent',
                'payload' => array_merge((array) $locked->payload, [
                    'sent_at' => $now->toIso8601String(),
                ]),
            ]);

            return true;
        });
    }

    private function defaultMessage(Reminder $reminder): string
    {
        $taskTitle = $reminder->task?->title;
        return $taskTitle
            ? "Reminder: {$taskTitle}"
            : 'Haman Planner reminder';
    }
}
