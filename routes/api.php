<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Middleware\ApiTokenMiddleware;

Route::get('/health', fn() => ['status' => 'ok', 'app' => 'haman-planner', 'author' => 'Reza Rafiei']);

Route::middleware(ApiTokenMiddleware::class)->group(function (): void {
    Route::apiResource('tasks', TaskController::class);
    Route::apiResource('goals', GoalController::class);
});
