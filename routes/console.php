<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('planner:reminders')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('billing:lifecycle')
    ->hourly()
    ->withoutOverlapping();

// Heartbeat for Admin → System health (stored in the DB so every container can read it).
Schedule::call(function (): void {
    DB::table('app_settings')->updateOrInsert(['key' => 'scheduler_heartbeat'], ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]);
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();
