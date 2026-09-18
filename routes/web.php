<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'planner.dashboard')->name('planner.dashboard');
Route::view('/planner', 'planner.dashboard')->name('planner.app');
