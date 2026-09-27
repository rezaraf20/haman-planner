<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;

final class ProcessBillingLifecycle extends Command
{
    protected $signature = 'billing:lifecycle';
    protected $description = 'Expire ended subscription periods and send renewal reminders.';

    public function handle(BillingService $billing, TelegramService $telegram): int
    {
        $r = $billing->processLifecycle($telegram);
        $this->info("Expired {$r['expired']} subscription(s); sent {$r['reminded']} renewal reminder(s).");
        return self::SUCCESS;
    }
}
