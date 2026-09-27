<?php
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminBusinessController;
use App\Http\Controllers\Admin\AdminPanelController;
use App\Http\Controllers\Admin\AdminSupportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SupportController;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public marketing (indexable)
Route::get('/', [MarketingController::class, 'home'])->name('marketing.home')->defaults('locale', 'fa')->defaults('indexable', true);
Route::get('/en', [MarketingController::class, 'home'])->name('marketing.home.en')->defaults('locale', 'en')->defaults('indexable', true);
Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing')->defaults('locale', 'fa')->defaults('indexable', true);
Route::get('/en/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing.en')->defaults('locale', 'en')->defaults('indexable', true);
Route::get('/privacy', [MarketingController::class, 'privacy'])->name('marketing.privacy')->defaults('locale', 'fa')->defaults('indexable', true);
Route::get('/en/privacy', [MarketingController::class, 'privacy'])->name('marketing.privacy.en')->defaults('locale', 'en')->defaults('indexable', true);
Route::get('/terms', [MarketingController::class, 'terms'])->name('marketing.terms')->defaults('locale', 'fa')->defaults('indexable', true);
Route::get('/en/terms', [MarketingController::class, 'terms'])->name('marketing.terms.en')->defaults('locale', 'en')->defaults('indexable', true);
Route::get('/sitemap.xml', [MarketingController::class, 'sitemap'])->name('marketing.sitemap')->defaults('indexable', true);
Route::get('/robots.txt', [MarketingController::class, 'robots'])->name('marketing.robots')->defaults('indexable', true);

// ---------------------------------------------------------------- language switcher
Route::get('/language/{locale}', LocaleController::class)->name('locale.switch');

// ---------------------------------------------------------------- authentication
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name('login.submit');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,1')->name('register.submit');
Route::get('/forgot-password', [PasswordResetController::class, 'showForgot'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');

// Payment provider redirect (signed URL; verified server-to-server with the provider).
Route::match(['get', 'post'], '/billing/callback/{payment}', [BillingController::class, 'callback'])->middleware('throttle:30,1')->name('billing.callback');

// ---------------------------------------------------------------- signed-in area
Route::middleware(['auth', TrackLastSeen::class])->group(function (): void {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::post('/onboarding/{step}', [OnboardingController::class, 'store'])->whereNumber('step')->name('onboarding.store');

    Route::get('/planner', fn () => view('planner.dashboard'))->middleware('onboarded')->name('planner.app');
    Route::redirect('/app', '/planner')->name('planner.dashboard');

    Route::get('/settings', [AccountController::class, 'settings'])->name('account.settings');
    Route::redirect('/settings/security', '/settings');
    Route::post('/settings/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::post('/settings/preferences', [AccountController::class, 'preferences'])->name('account.preferences');
    Route::post('/settings/security/password', [AccountController::class, 'password'])->name('account.password');
    Route::post('/settings/telegram/link', [AccountController::class, 'telegramLink'])->middleware('throttle:10,1')->name('account.telegram.link');
    Route::post('/settings/telegram/unlink', [AccountController::class, 'telegramUnlink'])->name('account.telegram.unlink');
    Route::get('/settings/export', [AccountController::class, 'export'])->middleware('throttle:5,1')->name('account.export');
    Route::post('/settings/delete', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('account.destroy');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::post('/billing/trial', [BillingController::class, 'trial'])->name('billing.trial');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');

    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:20,1')->name('support.reply');
    Route::post('/support/{ticket}/close', [SupportController::class, 'close'])->whereNumber('ticket')->name('support.close');

    Route::middleware('admin')->prefix('admin')->group(function (): void {
        Route::get('/', [AdminPanelController::class, 'overview'])->name('admin.home');
        Route::get('/users', [AdminPanelController::class, 'users'])->name('admin.users');
        Route::get('/users/export', [AdminPanelController::class, 'exportUsers'])->name('admin.users.export');
        Route::post('/users/{user}/toggle', [AdminPanelController::class, 'toggleUser'])->name('admin.users.toggle');
        Route::post('/users/{user}/grant', [AdminBusinessController::class, 'grant'])->name('admin.users.grant');
        Route::view('/access', 'planner.users')->name('admin.access');
        Route::get('/settings', [AdminPanelController::class, 'settings'])->name('admin.settings');
        Route::post('/settings', [AdminPanelController::class, 'saveSettings'])->name('admin.settings.save');
        Route::get('/subscriptions', [AdminBusinessController::class, 'subscriptions'])->name('admin.subscriptions');
        Route::get('/plans', [AdminBusinessController::class, 'plans'])->name('admin.plans');
        Route::post('/plans', [AdminBusinessController::class, 'savePlan'])->name('admin.plans.save');
        Route::get('/payments', [AdminBusinessController::class, 'payments'])->name('admin.payments');
        Route::get('/system', [AdminBusinessController::class, 'system'])->name('admin.system');
        Route::get('/support', [AdminSupportController::class, 'index'])->name('admin.support');
        Route::get('/support/{ticket}', [AdminSupportController::class, 'show'])->name('admin.support.show');
        Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply'])->name('admin.support.reply');
        Route::post('/support/{ticket}/status', [AdminSupportController::class, 'status'])->name('admin.support.status');
    });
});
