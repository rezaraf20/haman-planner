<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuthMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('planner.app');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ], AuthMessages::MESSAGES, AuthMessages::ATTRIBUTES);

        $key = 'planner-login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'email' => 'تعداد تلاش‌های ورود بیش از حد مجاز است. چند دقیقه بعد دوباره تلاش کنید.',
            ]);
        }

        $stored = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->value('email');

        if (! Auth::attempt(['email' => $stored ?? $data['email'], 'password' => $data['password'], 'is_active' => true], (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => 'ایمیل یا رمز عبور صحیح نیست.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('planner.app'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
