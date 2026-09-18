<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), ['POST','PUT','PATCH','DELETE'], true)) return $next($request);
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') return $next($request);
        if (strlen($key) > 200) return response()->json(['message'=>'Invalid Idempotency-Key'],422);

        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->user()?->getAuthIdentifier().'|'.$key);
        $cacheKey = 'haman:idempotency:'.$fingerprint;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response($cached['body'], (int)$cached['status'], (array)$cached['headers']);
        }

        $response = $next($request);
        if ($response->getStatusCode() < 500) {
            Cache::put($cacheKey, [
                'body' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'headers' => ['Content-Type'=>$response->headers->get('Content-Type','application/json')],
            ], now()->addMinutes(30));
        }
        return $response;
    }
}
