<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Telegram\TelegramService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

final class PasswordResetController extends Controller
{
    public function showForgot(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('planner.app');
        }
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request, TelegramService $telegram): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->where('is_active', true)->first();

        if ($user) {
            $tokens = Password::broker()->getRepository();
            if (!$tokens->recentlyCreatedToken($user)) {
                $token = $tokens->create($user);
                try {
                    $user->notify(new ResetPasswordNotification($token));
                } catch (\Throwable $e) {
                    report($e);
                }
                if ($user->telegram_chat_id) {
                    try {
                        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
                        $telegram->sendMessage($user->telegram_chat_id, __('auth.reset_telegram', [
                            'minutes' => config('auth.passwords.users.expire', 60), 'url' => $url,
                        ], $user->preferredLocale()));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        }

        // Same answer whether or not the account exists (no account enumeration).
        return back()->with('status', __('auth.reset_link_sent'));
    }

    public function showReset(Request $request, string $token): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('planner.app');
        }
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $data['email'] = trim($data['email']);

        $status = Password::broker()->reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __('auth.reset_done'));
        }

        return back()->withInput(['email' => $data['email']])->withErrors(['email' => __('auth.reset_invalid')]);
    }
}
