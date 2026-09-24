<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
final class AccountController {
 public function settings(): View { return view('planner.settings'); }
 public function password(Request $request): RedirectResponse { $data=$request->validate(['current_password'=>['required','current_password:web'],'password'=>['required','string','min:12','confirmed']]); $request->user()->update(['password'=>Hash::make($data['password'])]); return back()->with('status','رمز عبور با موفقیت تغییر کرد.'); }
 public function telegramLink(Request $request): RedirectResponse { $code=strtoupper(Str::random(8)); Cache::put('telegram:link:'.$code,(int)$request->user()->id,now()->addMinutes(15)); return back()->with('telegram_link_code',$code)->with('status','کد اتصال تلگرام برای ۱۵ دقیقه معتبر است. آن را برای ربات ارسال کن.'); }
 public function telegramUnlink(Request $request): RedirectResponse { $request->user()->update(['telegram_chat_id'=>null,'telegram_username'=>null,'telegram_linked_at'=>null]); return back()->with('status','اتصال تلگرام قطع شد.'); }
}