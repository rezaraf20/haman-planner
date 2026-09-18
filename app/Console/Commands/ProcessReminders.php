<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Planner\ReminderService;
use Illuminate\Console\Command;

final class ProcessReminders extends Command
{
    protected $signature = 'planner:reminders';
    protected $description = 'Dispatch due Haman Planner reminders.';

    public function handle(ReminderService $reminders): int
    {
        $count = $reminders->dispatchDue();
        $this->info("Dispatched {$count} reminder(s).");

        return self::SUCCESS;
    }
}
