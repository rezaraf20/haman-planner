<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Analytics\ProductEvents;
use App\Support\AppSettings;
use App\Support\Timezones;
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
        return AppSettings::bool('registration_enabled');
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
                    $fail(__('auth.email_taken'));
                }
            }],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $tz = (string) ($data['timezone'] ?? '');

        $user = User::create([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => false,
            'is_active' => true,
            // New accounts start in the language they signed up in and the browser's timezone.
            'locale' => app()->getLocale(),
            'timezone' => Timezones::valid($tz) ? $tz : (string) config('app.timezone'),
            'onboarded_at' => null,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        ProductEvents::record($user, ProductEvents::REGISTERED, ['locale' => $user->locale]);
        app(\App\Services\Notifications\LifecycleMailer::class)->send($user, 'welcome', [], 'welcome');
        EmailVerificationController::sendLink($user);

        return redirect()->route('onboarding')->with('status', __('auth.registered'));
    }
}
