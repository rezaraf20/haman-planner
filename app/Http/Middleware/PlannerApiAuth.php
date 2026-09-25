<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates planner API requests (web session, personal API token or the legacy
 * APP_API_TOKEN). Implementing AuthenticatesRequests places this middleware before
 * SubstituteBindings in Laravel's middleware priority, so route-model binding runs with
 * the user known and the planner ownership scope applied.
 */
final class PlannerApiAuth implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();

        // Browser session (dashboard) when no bearer token is sent.
        if ($plain === '') {
            if (!Auth::guard('web')->check()) {
                return response()->json(['message' => 'Unauthorized.'], 401);
            }
            if ($request->user()->is_active === false) {
                return response()->json(['message' => 'Account disabled.'], 403);
            }
            if (!$request->isMethodSafe()) {
                $s = (string) $request->session()->token();
                $p = (string) $request->header('X-CSRF-TOKEN');
                if ($s === '' || $p === '' || !hash_equals($s, $p)) {
                    return response()->json(['message' => 'CSRF token mismatch.'], 419);
                }
            }
            return $next($request);
        }

        // Legacy system token: acts as the planner owner (first active admin) so its
        // requests stay inside one user's data instead of seeing every user.
        $expected = (string) config('services.haman_planner.api_token', '');
        if ($expected !== '' && hash_equals($expected, $plain)) {
            $owner = User::query()->where('is_active', true)->orderByDesc('is_admin')->orderBy('id')->first();
            if (!$owner) {
                return response()->json(['message' => 'No active planner owner.'], 401);
            }
            Auth::guard('web')->setUser($owner);
            return $next($request);
        }

        $token = ApiToken::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();
        if (!$token || ($token->expires_at && $token->expires_at->isPast()) || !$token->user?->is_active) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $token->forceFill(['last_used_at' => now()])->save();
        Auth::guard('web')->setUser($token->user);
        return $next($request);
    }
}
