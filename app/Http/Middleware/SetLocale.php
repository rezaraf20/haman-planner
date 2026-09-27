<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the interface language for web and API requests, in this order:
 *  1. a locale fixed by the route (public /en/... marketing pages)
 *  2. the signed-in user's saved preference
 *  3. the visitor's explicit choice (session, then long-lived cookie)
 *  4. the browser's Accept-Language
 *  5. the application default (Persian)
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::normalize($request->route()?->defaults['locale'] ?? null)
            ?? ($request->user() ? $request->user()->preferredLocale() : null)
            ?? ($request->hasSession() ? Locales::normalize($request->session()->get('locale')) : null)
            ?? Locales::normalize($request->cookie(Locales::COOKIE))
            ?? Locales::fromBrowser($request)
            ?? Locales::normalize((string) config('app.locale'))
            ?? Locales::DEFAULT;

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        return $response;
    }
}
