<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\PlannerController;
use App\Http\Controllers\Api\DependencyController;
use App\Http\Controllers\Api\ExecutionLogController;
use App\Http\Middleware\ApiTokenMiddleware;

Route::get('/health', fn () => ['status'=>'ok','app'=>'haman-planner','author'=>'Reza Rafiei']);

Route::middleware(ApiTokenMiddleware::class)->group(function (): void {
    Route::apiResource('tasks', TaskController::class);
    Route::apiResource('goals', GoalController::class);
    Route::get('/planner/today', [PlannerController::class, 'today']);
    Route::get('/planner/analytics', [PlannerController::class, 'analytics']);
    Route::post('/tasks/{task}/dependencies', [DependencyController::class, 'store']);
    Route::delete('/tasks/{task}/dependencies/{dependency}', [DependencyController::class, 'destroy']);
    Route::post('/tasks/{task}/execution-logs', [ExecutionLogController::class, 'store']);
});
