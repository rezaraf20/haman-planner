<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>حساب و تلگرام | Haman Planner</title>
    <style>
        body{font-family:system-ui;background:#f5f7fa;margin:0;color:#172033}
        .wrap{max-width:760px;margin:60px auto;padding:24px}
        .card{background:#fff;border:1px solid #e2e7ee;border-radius:18px;padding:24px;margin-bottom:18px}
        .field{margin:14px 0}
        .field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
        .field input{width:100%;padding:12px;border:1px solid #d7dce5;border-radius:10px;box-sizing:border-box}
        .btn{background:#18212f;color:#fff;border:0;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer}
        .danger{background:#b42318}
        .ok{padding:10px;border-radius:10px;background:#ecfdf5;color:#047857;margin-bottom:12px}
        .code{font-size:28px;letter-spacing:5px;font-weight:900;background:#f1f5f9;padding:14px;border-radius:12px;text-align:center;margin:14px 0}
        .muted{color:#64748b;font-size:13px;line-height:1.8}
        .err{padding:10px;border-radius:10px;background:#fff1f2;color:#be123c;margin-bottom:12px;line-height:1.8}
        .tg{display:inline-block;background:#229ED9;color:#fff;border-radius:10px;padding:11px 16px;font-weight:700;text-decoration:none;margin-top:6px}
    </style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <h1>حساب و تلگرام</h1>
        <p>{{ auth()->user()->name }} — {{ auth()->user()->email }}</p>

        @if (session('status'))
            <div class="ok">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif
        <p><a href="{{ route('planner.app') }}">ورود به Planner ←</a></p>
    </div>

    <div class="card">
        <h2>اتصال Telegram</h2>

        @if (auth()->user()->telegram_chat_id)
            <p>
                متصل است به:
                <b>
                    @if (auth()->user()->telegram_username)
                        {{ '@' . auth()->user()->telegram_username }}
                    @else
                        بدون username
                    @endif
                </b>
            </p>

            <p class="muted">
                Chat ID: {{ auth()->user()->telegram_chat_id }}<br>
                این اتصال باعث می‌شود فقط همین حساب بتواند اطلاعات Planner را از ربات ببیند.
            </p>

            <form method="post" action="{{ route('account.telegram.unlink') }}">
                @csrf
                <button class="btn danger" type="submit">قطع اتصال</button>
            </form>
        @else
            <p class="muted">
                برای اتصال امن، یک کد یک‌بارمصرف بساز و آن را در ربات با دستور /start ارسال کن.
                فقط ۱۵ دقیقه معتبر است.
            </p>

            <form method="post" action="{{ route('account.telegram.link') }}">
                @csrf
                <button class="btn" type="submit">ساخت کد اتصال Telegram</button>
            </form>

            @if (session('telegram_link_code'))
                <div class="code">{{ session('telegram_link_code') }}</div>
                @if (config('services.telegram.bot_username'))
                    <p>
                        <a class="tg" href="https://t.me/{{ ltrim(config('services.telegram.bot_username'), '@') }}?start={{ session('telegram_link_code') }}" target="_blank" rel="noopener">اتصال با یک کلیک در Telegram</a>
                    </p>
                    <p class="muted">
                        یا در ربات
                        <b>{{ '@' . ltrim(config('services.telegram.bot_username'), '@') }}</b>
                        بفرست:
                        <b>/start {{ session('telegram_link_code') }}</b>
                    </p>
                @else
                    <p class="muted">
                        در Telegram بفرست:
                        <b>/start {{ session('telegram_link_code') }}</b>
                    </p>
                @endif
            @endif
        @endif
    </div>

    <div class="card">
        <h2>تغییر رمز عبور</h2>

        <form method="post" action="{{ route('account.password') }}">
            @csrf

            <div class="field">
                <label>رمز فعلی</label>
                <input type="password" name="current_password" required>
            </div>

            <div class="field">
                <label>رمز جدید</label>
                <input type="password" name="password" minlength="8" required>
            </div>

            <div class="field">
                <label>تکرار رمز جدید</label>
                <input type="password" name="password_confirmation" minlength="8" required>
            </div>

            <button class="btn" type="submit">ذخیره</button>
        </form>
    </div>

    <p>
        <a href="{{ route('planner.app') }}">بازگشت به Planner</a>
    </p>

</div>
</body>
</html>
