<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifications\LifecycleMailer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Signed one-click unsubscribe from optional lifecycle email. GET shows a confirmation (so link
 * scanners in mail clients do not unsubscribe people); POST applies it — also used by the
 * List-Unsubscribe header. Works without being logged in; the signature identifies the user.
 */
final class EmailPreferenceController extends Controller
{
    public function show(Request $request, User $user, string $preference): View
    {
        abort_unless(in_array($preference, LifecycleMailer::UNSUBSCRIBABLE, true), 404);
        app()->setLocale($user->preferredLocale());
        return view('emails.unsubscribe', ['user' => $user, 'preference' => $preference, 'done' => false, 'action' => $request->fullUrl()]);
    }

    public function update(Request $request, User $user, string $preference): View
    {
        abort_unless(in_array($preference, LifecycleMailer::UNSUBSCRIBABLE, true), 404);
        $prefs = is_array($user->preferences) ? $user->preferences : [];
        $prefs[$preference] = false;
        $user->forceFill(['preferences' => $prefs])->save();
        app()->setLocale($user->preferredLocale());
        return view('emails.unsubscribe', ['user' => $user, 'preference' => $preference, 'done' => true, 'action' => null]);
    }
}
