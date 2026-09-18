<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\ActivityLog;

final class ActivityLogger
{
    public function log(string $action, string $entityType, string|int|null $entityId, ?array $before = null, ?array $after = null): ActivityLog
    {
        return ActivityLog::create([
            'actor_type' => 'system',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }
}
