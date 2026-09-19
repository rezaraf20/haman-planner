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
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\AdminController;
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

    Route::apiResource('areas', AreaController::class);
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
    Route::get('/tasks/{task}/dependencies', [DependencyController::class, 'index']);
    Route::post('/tasks/{task}/dependencies', [DependencyController::class, 'store']);
    Route::delete('/tasks/{task}/dependencies/{dependency}', [DependencyController::class, 'destroy']);
    Route::get('/tasks/{task}/execution-logs', [ExecutionLogController::class, 'index']);
    Route::post('/tasks/{task}/execution-logs', [ExecutionLogController::class, 'store']);
    Route::put('/tasks/{task}/execution-logs/{executionLog}', [ExecutionLogController::class, 'update']);
    Route::delete('/tasks/{task}/execution-logs/{executionLog}', [ExecutionLogController::class, 'destroy']);

    Route::get('/reminders', [ReminderController::class, 'index']);
    Route::get('/reminders/{reminder}', [ReminderController::class, 'show']);
    Route::post('/reminders', [ReminderController::class, 'store']);
    Route::put('/reminders/{reminder}', [ReminderController::class, 'update']);
    Route::delete('/reminders/{reminder}', [ReminderController::class, 'destroy']);
    Route::post('/reminders/{reminder}/cancel', [ReminderController::class, 'cancel']);

    Route::post('/commands', [CommandController::class, 'handle']);
    Route::get('/reviews', [ReviewController::class, 'index']);
    Route::get('/system/dashboard', [SystemController::class, 'dashboard']);
    Route::get('/system/activity', [SystemController::class, 'activity']);
    Route::get('/system/ai-interactions', [SystemController::class, 'ai']);
    Route::get('/system/pending-actions', [SystemController::class, 'pending']);
    Route::get('/system/failures', [SystemController::class, 'failures']);
    Route::get('/system/execution', [SystemController::class, 'execution']);
    Route::get('/system/daily-plans', [SystemController::class, 'dailyPlans']);
    Route::get('/system/report', [SystemController::class, 'report']);
    Route::post('/reviews/generate', [ReviewController::class, 'generate']);
});

Route::middleware(['auth','admin'])->prefix('admin')->group(function(): void {
  Route::get('/users',[AdminController::class,'users']);
  Route::post('/users',[AdminController::class,'storeUser']);
  Route::put('/users/{user}',[AdminController::class,'updateUser']);
  Route::delete('/users/{user}',[AdminController::class,'destroyUser']);
  Route::get('/tokens',[AdminController::class,'tokens']);
  Route::post('/tokens',[AdminController::class,'createToken']);
  Route::delete('/tokens/{token}',[AdminController::class,'revokeToken']);
 });
