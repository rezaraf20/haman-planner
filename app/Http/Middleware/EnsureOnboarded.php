<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sends new accounts through the short onboarding flow once, before the planner. */
final class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->onboarded_at === null && !$request->expectsJson()) {
            return redirect()->route('onboarding');
        }
        return $next($request);
    }
}
