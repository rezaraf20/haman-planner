<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every web response, plus `X-Robots-Tag: noindex` for
 * everything except public marketing pages (routes marked with the `indexable` default).
 *
 * Content-Security-Policy: everything is self-hosted (scripts, styles, fonts, icons), so the
 * policy is `'self'` everywhere. The existing pages use inline <script>/<style> blocks and inline
 * event handlers, so 'unsafe-inline' is kept for script/style (a nonce would disable the inline
 * handlers); the policy still blocks every third-party script, framing, plugins, <base> hijacking
 * and form posts to foreign sites. form-action allows the payment and OAuth providers because the
 * checkout form's redirect to them is subject to form-action. Responses that set their own CSP
 * (attachment downloads) keep it.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.force_https') && !$request->isSecure() && app()->environment('production') && in_array($request->method(), ['GET', 'HEAD'], true)) {
            return redirect()->secure($request->getRequestUri(), 301);
        }
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (config('app.csp', true) && !$h->has('Content-Security-Policy') && !$h->has('Content-Security-Policy-Report-Only')) {
            $h->set(config('app.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', self::policy());
        }
        if (!($request->route()?->defaults['indexable'] ?? false)) {
            $h->set('X-Robots-Tag', 'noindex, nofollow');
        }
        return $response;
    }

    public static function policy(): string
    {
        $formTargets = ["'self'", 'https://checkout.stripe.com', 'https://payment.zarinpal.com', 'https://sandbox.zarinpal.com', 'https://www.zarinpal.com', 'https://accounts.google.com'];
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            "connect-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            'form-action '.implode(' ', $formTargets),
        ]);
    }
}
