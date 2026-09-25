<?php
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;
Route::get('/login',[AuthController::class,'showLogin'])->name('login');
Route::post('/login',[AuthController::class,'login'])->middleware('throttle:8,1')->name('login.submit');
Route::post('/logout',[AuthController::class,'logout'])->name('logout');
Route::get('/register',[RegisterController::class,'show'])->name('register');
Route::post('/register',[RegisterController::class,'store'])->middleware('throttle:5,1')->name('register.submit');
Route::get('/forgot-password',[PasswordResetController::class,'showForgot'])->name('password.request');
Route::post('/forgot-password',[PasswordResetController::class,'sendLink'])->middleware('throttle:5,1')->name('password.email');
Route::get('/reset-password/{token}',[PasswordResetController::class,'showReset'])->name('password.reset');
Route::post('/reset-password',[PasswordResetController::class,'reset'])->middleware('throttle:10,1')->name('password.update');
Route::middleware('auth')->group(function():void{
 Route::get('/',fn()=>redirect()->route('planner.app'))->name('planner.dashboard');
 Route::view('/planner','planner.dashboard')->name('planner.app');
 Route::middleware('admin')->group(function():void{
  Route::view('/admin/users','planner.users')->name('admin.users');
 });
 Route::get('/settings/security',[AccountController::class,'settings'])->name('account.settings');
 Route::post('/settings/security/password',[AccountController::class,'password'])->name('account.password');
 Route::post('/settings/telegram/link',[AccountController::class,'telegramLink'])->name('account.telegram.link');
 Route::post('/settings/telegram/unlink',[AccountController::class,'telegramUnlink'])->name('account.telegram.unlink');
});
