<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
final class PlannerApiAuth {
 public function handle(Request $request, Closure $next): Response {
  if(Auth::guard('web')->check()){
   if($request->user()->is_active===false)return response()->json(['message'=>'Account disabled.'],403);
   if($request->isMethodSafe(false)){return $next($request);}
   $s=(string)$request->session()->token(); $p=(string)$request->header('X-CSRF-TOKEN');
   if($s===''||$p===''||!hash_equals($s,$p))return response()->json(['message'=>'CSRF token mismatch.'],419);
   return $next($request);
  }
  $plain=(string)$request->bearerToken();
  if($plain==='')return response()->json(['message'=>'Unauthorized.'],401);
  $expected=(string) config('services.haman_planner.api_token','');
  if($expected!=='' && hash_equals($expected,$plain)) return $next($request);
  $token=ApiToken::query()->with('user')->where('token_hash',hash('sha256',$plain))->first();
  if(!$token||($token->expires_at&&$token->expires_at->isPast())||!$token->user->is_active)return response()->json(['message'=>'Unauthorized.'],401);
  $token->forceFill(['last_used_at'=>now()])->save();
  Auth::guard('web')->setUser($token->user);
  return $next($request);
 }
}