<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\ActivityLog;
use App\Support\ActivityContext;
use App\Support\PlannerUserContext;

final class ActivityLogger
{
    public function log(string $action, string $entityType, string|int|null $entityId, ?array $before = null, ?array $after = null, ?string $actor = null): ActivityLog
    {
        $actor ??= ActivityContext::actor();
        $userId = PlannerUserContext::id() ?? auth()->id();
        $ownerId = $userId;
        if ($ownerId === null && $entityId !== null && class_exists($entityType) && method_exists($entityType, 'scopeOwnedBy')) {
            // Background jobs: attribute the entry to the entity's owner so it appears in their timeline.
            $ownerId = $entityType::withoutGlobalScopes()->whereKey($entityId)->value('user_id');
        }
        return ActivityLog::create([
            'user_id' => $ownerId,
            'actor_type' => $actor,
            'actor_id' => $actor === 'system' ? null : ($userId !== null ? (string) $userId : null),
            'channel' => ActivityContext::channel(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }
}
