<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\PlannerController;
use App\Http\Controllers\Api\DependencyController;
use App\Http\Controllers\Api\ExecutionLogController;
use App\Http\Controllers\Api\CommandController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\AIPlannerController;
use App\Http\Controllers\Api\DailyPlanController;
use App\Http\Controllers\Api\ScheduleBlockController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\PlannerApiAuth;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;

Route::get('/health', fn (): array => ['status' => 'ok', 'app' => 'haman-planner', 'author' => 'Reza Rafiei']);

Route::get('/ready', function () {
    try {
        DB::connection()->getPdo();
        return response()->json(['status' => 'ready', 'database' => 'ok']);
    } catch (\Throwable) {
        return response()->json(['status' => 'not_ready', 'database' => 'unavailable'], 503);
    }
});

Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:30,1');

Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    RequestIdMiddleware::class,
    PlannerApiAuth::class,
    IdempotencyMiddleware::class,
    'throttle:120,1',
])->group(function (): void {
    Route::get('/me', fn () => response()->json(['user' => request()->user()]));

    Route::apiResource('areas', AreaController::class)->except(['show']);
    Route::apiResource('tasks', TaskController::class);
    Route::apiResource('goals', GoalController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('milestones', MilestoneController::class);
    Route::apiResource('notes', NoteController::class);
    Route::apiResource('decisions', DecisionController::class);

    Route::get('/planner/today', [PlannerController::class, 'today']);
    Route::get('/planner/analytics', [PlannerController::class, 'analytics']);
    Route::get('/planner/recommendations', RecommendationController::class);
    Route::post('/planner/ai-recommendations', AIPlannerController::class);

    Route::get('/daily-plans', [DailyPlanController::class, 'index']);
    Route::get('/daily-plans/{date}', [DailyPlanController::class, 'show']);
    Route::post('/daily-plans', [DailyPlanController::class, 'store']);
    Route::put('/daily-plans/{dailyPlan}', [DailyPlanController::class, 'update']);

    Route::apiResource('schedule-blocks', ScheduleBlockController::class)->except(['show']);

    Route::get('/search', SearchController::class);
    Route::post('/tasks/{task}/dependencies', [DependencyController::class, 'store']);
    Route::delete('/tasks/{task}/dependencies/{dependency}', [DependencyController::class, 'destroy']);
    Route::post('/tasks/{task}/execution-logs', [ExecutionLogController::class, 'store']);

    Route::get('/reminders', [ReminderController::class, 'index']);
    Route::post('/reminders', [ReminderController::class, 'store']);
    Route::delete('/reminders/{reminder}', [ReminderController::class, 'destroy']);
    Route::post('/reminders/{reminder}/cancel', [ReminderController::class, 'cancel']);

    Route::post('/commands', [CommandController::class, 'handle']);
    Route::get('/reviews', [ReviewController::class, 'index']);
    Route::post('/reviews/generate', [ReviewController::class, 'generate']);
});
