<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Planner\RecurrenceService;
use Illuminate\Console\Command;

final class GenerateRecurringTasks extends Command
{
    protected $signature = 'planner:recurring';
    protected $description = 'Create upcoming occurrences of recurring tasks (only up to the configured horizon).';

    public function handle(RecurrenceService $recurrence): int
    {
        $this->info('Created '.$recurrence->generateAll().' occurrence(s).');
        return self::SUCCESS;
    }
}
