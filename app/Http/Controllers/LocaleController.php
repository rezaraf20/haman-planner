<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Language switcher: remembers the choice (account, session, cookie) and returns to the same page. */
final class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $locale = Locales::normalize($locale);
        abort_if($locale === null, 404);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }
        $request->session()->put('locale', $locale);

        return redirect()->to(self::safeTarget((string) $request->query('to', '/')))
            ->withCookie(cookie()->forever(Locales::COOKIE, $locale, null, null, null, true, false, false, 'lax'));
    }

    /** Only same-site relative paths are accepted (no open redirects). */
    public static function safeTarget(string $to): string
    {
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_starts_with($to, '/\\') || preg_match('/[\r\n]/', $to)) {
            return '/';
        }
        return $to;
    }
}
