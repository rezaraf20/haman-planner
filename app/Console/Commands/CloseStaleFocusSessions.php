<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Planner\FocusService;
use Illuminate\Console\Command;

final class CloseStaleFocusSessions extends Command
{
    protected $signature = 'planner:focus-cleanup';
    protected $description = 'Close focus timers left running for many hours (no time is logged for them).';

    public function handle(FocusService $focus): int
    {
        $this->info('Closed '.$focus->closeStale().' stale focus session(s).');
        return self::SUCCESS;
    }
}
