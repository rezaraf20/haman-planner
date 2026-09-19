<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
final class AdminController extends Controller {
 public function users(): JsonResponse { return response()->json(User::query()->orderBy('name')->get(['id','name','email','is_admin','is_active','created_at'])); }
 public function storeUser(Request $r): JsonResponse {
  $d=$r->validate(['name'=>'required|string|max:120','email'=>'required|email|max:255|unique:users,email','password'=>'required|string|min:12','is_admin'=>'nullable|boolean','is_active'=>'nullable|boolean']);
  $d['password']=Hash::make($d['password']); $u=User::create($d); return response()->json($u->only(['id','name','email','is_admin','is_active']),201);
 }
 public function updateUser(Request $r,User $user): JsonResponse {
  $d=$r->validate(['name'=>'sometimes|string|max:120','email'=>'sometimes|email|max:255|unique:users,email,'.$user->id,'password'=>'nullable|string|min:12','is_admin'=>'sometimes|boolean','is_active'=>'sometimes|boolean']);
  if(array_key_exists('password',$d)){ if($d['password'])$d['password']=Hash::make($d['password']); else unset($d['password']); }
  $user->update($d); return response()->json($user->only(['id','name','email','is_admin','is_active']));
 }
 public function destroyUser(User $user): JsonResponse {
  if($user->id===request()->user()->id) return response()->json(['message'=>'حساب فعلی قابل حذف نیست.'],422);
  $user->delete(); return response()->json(null,204);
 }
 public function tokens(): JsonResponse { return response()->json(ApiToken::where('user_id',request()->user()->id)->latest()->get(['id','name','token_prefix','last_used_at','expires_at','created_at'])); }
 public function createToken(Request $r): JsonResponse {
  $d=$r->validate(['name'=>'required|string|max:100','expires_at'=>'nullable|date|after:now']);
  $plain=Str::random(64); $t=ApiToken::create(['user_id'=>request()->user()->id,'name'=>$d['name'],'token_hash'=>hash('sha256',$plain),'token_prefix'=>substr($plain,0,12),'expires_at'=>$d['expires_at']??null]);
  return response()->json(['token'=>$plain,'id'=>$t->id,'name'=>$t->name,'warning'=>'این توکن فقط همین یک بار نمایش داده می‌شود.'],201);
 }
 public function revokeToken(ApiToken $token): JsonResponse {
  abort_unless($token->user_id===request()->user()->id,403); $token->delete(); return response()->json(null,204);
 }
}