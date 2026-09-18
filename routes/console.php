<?php
use Illuminate\Support\Facades\Schedule;

Schedule::command('planner:reminders')
    ->everyMinute()
    ->withoutOverlapping();
