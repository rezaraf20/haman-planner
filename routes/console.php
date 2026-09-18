<?php
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    // Haman Planner scheduled jobs will be registered here.
})->dailyAt('03:00')->withoutOverlapping();
