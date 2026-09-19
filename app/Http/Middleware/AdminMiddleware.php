<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
final class AdminMiddleware {
 public function handle(Request $request, Closure $next) {
  abort_unless($request->user()?->is_admin===true,403);
  return $next($request);
 }
}