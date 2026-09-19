<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AccountController extends Controller
{
    public function settings(): View
    {
        return view('planner.settings');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return back()->with('status', 'رمز عبور با موفقیت تغییر کرد.');
    }
}
