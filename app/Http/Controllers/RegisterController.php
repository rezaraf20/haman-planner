<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AuthMessages;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

final class RegisterController extends Controller
{
    public static function enabled(): bool
    {
        return \App\Support\AppSettings::bool('registration_enabled');
    }

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('planner.app');
        }
        abort_unless(self::enabled(), 404);

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        // Honeypot: real users never fill this hidden field.
        if (filled($request->input('website'))) {
            return redirect()->route('login');
        }

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', function (string $attr, mixed $value, \Closure $fail): void {
                if (User::query()->whereRaw('lower(email) = ?', [(string) $value])->exists()) {
                    $fail('این ایمیل قبلاً ثبت شده است. وارد شوید یا از «فراموشی رمز عبور» استفاده کنید.');
                }
            }],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], AuthMessages::MESSAGES, AuthMessages::ATTRIBUTES);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => false,
            'is_active' => true,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account.settings')->with('status', 'حساب شما ساخته شد 🎉 برای استفاده از ربات، Telegram را از همین صفحه متصل کنید.');
    }
}
