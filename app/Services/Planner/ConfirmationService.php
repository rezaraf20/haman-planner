<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\PendingAction;
use Illuminate\Support\Carbon;

final class ConfirmationService
{
    public function create(string|int $chatId, string $intent, array $payload, int $ttlMinutes = 10): PendingAction
    {
        PendingAction::query()
            ->where('chat_id', (string) $chatId)
            ->where('status', 'pending')
            ->update(['status' => 'superseded']);

        return PendingAction::create([
            'chat_id' => (string) $chatId,
            'intent' => $intent,
            'payload' => $payload,
            'status' => 'pending',
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    public function latest(string|int $chatId): ?PendingAction
    {
        return PendingAction::query()
            ->where('chat_id', (string) $chatId)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    public function approve(string|int $chatId): ?PendingAction
    {
        $action = $this->latest($chatId);
        if (! $action) return null;

        $action->update(['status' => 'approved']);
        return $action->refresh();
    }

    public function reject(string|int $chatId): ?PendingAction
    {
        $action = $this->latest($chatId);
        if (! $action) return null;

        $action->update(['status' => 'rejected']);
        return $action->refresh();
    }
}
