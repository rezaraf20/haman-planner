<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps users.last_seen_at roughly current (throttled to one write per 5 minutes). */
final class TrackLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            $request->user()?->markSeen();
        } catch (\Throwable) {
            // Never break a request because of activity tracking.
        }
        return $response;
    }
}
