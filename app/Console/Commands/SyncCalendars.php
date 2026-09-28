<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Calendar\CalendarSyncService;
use Illuminate\Console\Command;

final class SyncCalendars extends Command
{
    protected $signature = 'calendar:sync';
    protected $description = 'Import busy time from connected calendars and export planner blocks (where enabled).';

    public function handle(CalendarSyncService $sync): int
    {
        $this->info('Synced '.$sync->syncAll().' calendar connection(s).');
        return self::SUCCESS;
    }
}
