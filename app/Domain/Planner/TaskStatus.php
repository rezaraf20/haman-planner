<?php

declare(strict_types=1);

namespace App\Domain\Planner;

enum TaskStatus: string
{
    case Inbox = 'inbox';
    case Planned = 'planned';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Deferred = 'deferred';
}
