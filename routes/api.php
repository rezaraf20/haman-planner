<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\GoalController;
Route::get('/health', fn() => ['status'=>'ok','app'=>'haman-planner']);
Route::apiResource('tasks', TaskController::class);
Route::apiResource('goals', GoalController::class);
