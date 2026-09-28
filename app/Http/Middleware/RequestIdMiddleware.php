<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Request-Id', '');
        if ($id === '' || strlen($id) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $id)) $id = (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        // Added to every log line of this request and carried into queued jobs it dispatches.
        \Illuminate\Support\Facades\Context::add('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        return $response;
    }
}
