<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود | Haman Planner</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:#0f172a;color:#172033;display:grid;place-items:center}
.card{width:min(430px,calc(100% - 32px));background:#fff;border-radius:24px;padding:36px;box-shadow:0 25px 70px rgba(0,0,0,.28)}
.logo{font-size:26px;font-weight:850;letter-spacing:-.5px}.sub{color:#718096;margin:7px 0 28px}.field{margin:16px 0}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}.field input{width:100%;padding:13px 14px;border:1px solid #d9dee8;border-radius:12px;font-size:15px;outline:none}.field input:focus{border-color:#18212f;box-shadow:0 0 0 3px #e8ebef}.btn{width:100%;border:0;border-radius:12px;padding:13px;background:#18212f;color:#fff;font-weight:800;cursor:pointer;font-size:15px}.remember{display:flex;gap:8px;align-items:center;font-size:13px;color:#64748b;margin:12px 0 18px}.error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3;padding:10px 12px;border-radius:10px;font-size:13px}.hint{font-size:12px;color:#94a3b8;margin-top:20px;line-height:1.8}
</style>
</head>
<body><main class="card">
<div class="logo">Haman Planner</div><div class="sub">Personal & Business Operating System</div>
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('login.submit') }}">
@csrf
<div class="field"><label>ایمیل</label><input name="email" type="email" autocomplete="username" value="{{ old('email', env('PLANNER_ADMIN_EMAIL','admin@hamantech.ir')) }}" required></div>
<div class="field"><label>رمز عبور</label><input name="password" type="password" autocomplete="current-password" required></div>
<label class="remember"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label>
<button class="btn">ورود به Planner</button>
</form>
<div class="hint">برای اولین ورود، رمز اولیه همان رمز مدیریتی پیکربندی‌شده در سرور است. پس از ورود آن را از بخش امنیت تغییر دهید.</div>
</main></body></html>
