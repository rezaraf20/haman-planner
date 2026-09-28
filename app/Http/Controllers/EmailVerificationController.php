<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifications\LifecycleMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Email address verification with a signed, 60-minute link bound to the current address (a link
 * sent to an old address stops working after the email is changed). Verification is not required
 * to use the planner — existing accounts keep working — it is shown as a reminder in Settings.
 */
final class EmailVerificationController extends Controller
{
    public static function sendLink(User $user): bool
    {
        if ($user->email_verified_at !== null) {
            return false;
        }
        $url = URL::temporarySignedRoute('email.verify', now()->addMinutes(60), ['user' => $user->id, 'hash' => sha1(mb_strtolower((string) $user->email))]);
        return app(LifecycleMailer::class)->send($user, 'verify_email', ['url' => $url], 'verify-'.sha1((string) $user->email).'-'.now()->format('YmdHi'));
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return back()->with('status', __('settings.email_already_verified'));
        }
        self::sendLink($user);
        return back()->with('status', __('settings.verification_sent', ['email' => $user->email]));
    }

    public function verify(Request $request, User $user, string $hash): RedirectResponse
    {
        if (!hash_equals(sha1(mb_strtolower((string) $user->email)), $hash)) {
            abort(403);
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        $target = $request->user()?->id === $user->id ? route('account.settings') : route('login');
        return redirect()->to($target)->with('status', __('settings.email_verified', [], $user->preferredLocale()));
    }
}
