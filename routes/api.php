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

Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:30,1,a-telegram-webhook');

// Payment provider webhooks: authenticated by the provider's signature, not by a user session.
Route::post('/billing/webhook/stripe', \App\Http\Controllers\StripeWebhookController::class)->middleware([RequestIdMiddleware::class, 'throttle:120,1,a-stripe-webhook'])->name('billing.webhook.stripe');

Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    RequestIdMiddleware::class,
    PlannerApiAuth::class,
    \App\Http\Middleware\SetLocale::class,
    IdempotencyMiddleware::class,
    \App\Http\Middleware\TrackLastSeen::class,
    'throttle:120,1,api',
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

    Route::apiResource('schedule-blocks', ScheduleBlockController::class);

    // Haman AI planning (proposals are applied only on explicit confirmation)
    Route::post('/planning/proposals', [\App\Http\Controllers\Api\PlanningController::class, 'propose'])->middleware('throttle:20,1,a-planning-proposals');
    Route::get('/planning/proposals/{planProposal}', [\App\Http\Controllers\Api\PlanningController::class, 'show']);
    Route::post('/planning/proposals/{planProposal}/apply', [\App\Http\Controllers\Api\PlanningController::class, 'apply']);
    Route::post('/planning/proposals/{planProposal}/dismiss', [\App\Http\Controllers\Api\PlanningController::class, 'dismiss']);
    Route::get('/planning/what-now', [\App\Http\Controllers\Api\PlanningController::class, 'whatNow']);
    Route::get('/planning/insights', [\App\Http\Controllers\Api\PlanningController::class, 'insights']);
    Route::post('/reviews/weekly', [\App\Http\Controllers\Api\PlanningController::class, 'weeklyReview'])->middleware('throttle:10,1,a-reviews-weekly');
    Route::get('/reviews/{review}/details', [\App\Http\Controllers\Api\PlanningController::class, 'showReview']);

    // Attachments (private; ownership enforced on every call)
    Route::get('/{type}/{id}/attachments', [\App\Http\Controllers\Api\AttachmentController::class, 'index'])->whereIn('type', ['task', 'project'])->whereNumber('id');
    Route::post('/{type}/{id}/attachments', [\App\Http\Controllers\Api\AttachmentController::class, 'store'])->whereIn('type', ['task', 'project'])->whereNumber('id')->middleware('throttle:30,1,a-type-id-attachments');
    Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\Api\AttachmentController::class, 'download']);
    Route::delete('/attachments/{attachment}', [\App\Http\Controllers\Api\AttachmentController::class, 'destroy']);

    // Time blocking
    Route::get('/schedule', [\App\Http\Controllers\Api\ScheduleController::class, 'index']);
    Route::post('/schedule/place', [\App\Http\Controllers\Api\ScheduleController::class, 'place']);
    Route::patch('/schedule/{type}/{id}', [\App\Http\Controllers\Api\ScheduleController::class, 'move'])->whereIn('type', ['block', 'task'])->whereNumber('id');
    Route::post('/tasks/{task}/unschedule', [\App\Http\Controllers\Api\ScheduleController::class, 'unschedule']);

    // Recurring tasks (series); occurrences are ordinary tasks.
    Route::post('/recurring-tasks/preview', [\App\Http\Controllers\Api\RecurringTaskController::class, 'preview']);
    Route::get('/recurring-tasks', [\App\Http\Controllers\Api\RecurringTaskController::class, 'index']);
    Route::post('/recurring-tasks', [\App\Http\Controllers\Api\RecurringTaskController::class, 'store']);
    Route::get('/recurring-tasks/{recurringTask}', [\App\Http\Controllers\Api\RecurringTaskController::class, 'show']);
    Route::put('/recurring-tasks/{recurringTask}', [\App\Http\Controllers\Api\RecurringTaskController::class, 'update']);
    Route::post('/recurring-tasks/{recurringTask}/stop', [\App\Http\Controllers\Api\RecurringTaskController::class, 'stop']);
    Route::post('/tasks/{task}/skip', [\App\Http\Controllers\Api\RecurringTaskController::class, 'skip']);

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
    Route::get('/activity/timeline', function (\Illuminate\Http\Request $request) {
        $data = $request->validate(['before' => ['nullable', 'integer'], 'actor' => ['nullable', 'in:user,ai,system']]);
        return response()->json(app(\App\Services\Planner\ActivityTimelineService::class)->timeline($request->user(), $data['before'] ?? null, 50, $data['actor'] ?? null));
    });
    Route::get('/system/ai-interactions', [SystemController::class, 'ai']);
    Route::get('/system/pending-actions', [SystemController::class, 'pending']);
    Route::get('/system/failures', [SystemController::class, 'failures']);
    Route::get('/system/execution', [SystemController::class, 'execution']);
    Route::get('/system/dependencies', [SystemController::class, 'dependencies']);
    Route::get('/system/daily-plans', [SystemController::class, 'dailyPlans']);
    Route::get('/system/report', [SystemController::class, 'report']);
    Route::post('/reviews/generate', [ReviewController::class, 'generate']);

    Route::middleware('admin')->prefix('admin')->group(function (): void {
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::put('/users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::get('/tokens', [AdminController::class, 'tokens']);
        Route::post('/tokens', [AdminController::class, 'createToken']);
        Route::delete('/tokens/{token}', [AdminController::class, 'revokeToken']);
    });
});
