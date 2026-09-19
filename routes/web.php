<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/', fn () => redirect()->route('planner.app'))->name('planner.dashboard');
    Route::view('/planner', 'planner.dashboard')->name('planner.app');
    Route::get('/settings/security', [AccountController::class, 'settings'])->name('account.settings');
    Route::post('/settings/password', [AccountController::class, 'password'])->name('account.password');
});
