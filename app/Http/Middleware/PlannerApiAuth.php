<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class PlannerApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            if ($request->isMethodSafe(false)) {
                return $next($request);
            }

            $sessionToken = (string) $request->session()->token();
            $providedToken = (string) $request->header('X-CSRF-TOKEN');

            if ($sessionToken === '' || $providedToken === '' || ! hash_equals($sessionToken, $providedToken)) {
                return response()->json(['message' => 'CSRF token mismatch.'], 419);
            }

            return $next($request);
        }

        $expected = (string) config('services.haman_planner.api_token', '');
        $provided = (string) $request->bearerToken();

        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
