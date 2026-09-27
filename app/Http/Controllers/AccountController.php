<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\AccountDataService;
use App\Services\Billing\Entitlements;
use App\Support\Locales;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The signed-in user's own account: profile, preferences, security, Telegram and privacy. */
final class AccountController extends Controller
{
    public function settings(Request $request, Entitlements $entitlements): View
    {
        return view('account.settings', [
            'user' => $request->user(),
            'timezones' => Timezones::options(),
            'plan' => $entitlements->plan($request->user()),
            'telegramAllowed' => $entitlements->canUse($request->user(), 'telegram'),
        ]);
    }

    public function profile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'current_password' => ['nullable', 'string'],
        ]);

        if ($data['email'] !== mb_strtolower((string) $user->email)) {
            if (!filled($data['current_password'] ?? null) || !Hash::check((string) $data['current_password'], (string) $user->password)) {
                return back()->withErrors(['current_password' => __('settings.email_needs_password')])->withInput();
            }
            if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$data['email']])->exists()) {
                return back()->withErrors(['email' => __('settings.email_taken')])->withInput();
            }
        }

        $user->forceFill(['name' => trim($data['name']), 'email' => $data['email']])->save();
        return back()->with('status', __('settings.profile_saved'));
    }

    public function preferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(Locales::SUPPORTED)],
            'timezone' => ['required', 'timezone:all'],
            'ai_response_language' => ['required', Rule::in(array_merge(['auto'], Locales::SUPPORTED))],
        ]);
        $user = $request->user();
        $prefs = [
            'ai_response_language' => $data['ai_response_language'],
        ];
        foreach (['notify_reminders_telegram', 'notify_support_email', 'notify_billing_email', 'weekly_summary_telegram', 'ai_enabled'] as $flag) {
            $prefs[$flag] = $request->boolean($flag);
        }
        $user->forceFill([
            'locale' => $data['locale'],
            'timezone' => $data['timezone'],
            'preferences' => array_merge(is_array($user->preferences) ? $user->preferences : [], $prefs),
        ])->save();
        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);

        return redirect()->route('account.settings')->with('status', __('settings.preferences_saved'));
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        return back()->with('status', __('settings.password_changed'));
    }

    public function telegramLink(Request $request): RedirectResponse
    {
        $code = strtoupper(Str::random(8));
        Cache::put('telegram:link:'.$code, (int) $request->user()->id, now()->addMinutes(15));
        return back()->with('telegram_link_code', $code)->with('status', __('settings.telegram_code_ready'));
    }

    public function telegramUnlink(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->telegram_chat_id !== null) {
            Cache::forget('telegram:planner:state:'.$user->telegram_chat_id);
        }
        $user->forceFill(['telegram_chat_id' => null, 'telegram_username' => null, 'telegram_linked_at' => null])->save();
        return back()->with('status', __('settings.telegram_unlinked'));
    }

    public function export(Request $request, AccountDataService $data): StreamedResponse
    {
        $payload = $data->export($request->user());
        return response()->streamDownload(function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'haman-planner-export-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function destroy(Request $request, AccountDataService $data): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'confirm' => ['required', 'string'],
        ]);
        $word = (string) __('settings.delete_confirm_word');
        if (mb_strtolower(trim((string) $request->input('confirm'))) !== mb_strtolower($word)) {
            return back()->withErrors(['confirm' => __('settings.delete_wrong_word')]);
        }
        $user = $request->user();
        if ($user->is_admin && User::query()->where('is_admin', true)->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['confirm' => __('settings.delete_last_admin')]);
        }

        $locale = app()->getLocale();
        Auth::logout();
        $data->delete($user);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('locale', $locale);

        return redirect()->route('login')->with('status', __('settings.deleted'));
    }
}
