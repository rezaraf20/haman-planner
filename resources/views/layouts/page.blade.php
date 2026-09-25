@php($brand = \App\Support\AppSettings::all())
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') | {{ $brand['app_name'] }}</title>
<style>
:root{--bg:#f4f6f9;--card:#fff;--ink:#172033;--muted:#64748b;--line:#e4e8ef;--dark:#0f1726;--ok:#087f5b;--warn:#9a6509;--danger:#b42318;--blue:#3157d5}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Vazirmatn,Inter,system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;font-size:14px}
a{color:var(--blue);text-decoration:none}a:hover{text-decoration:underline}
.top{background:var(--dark);color:#fff}.top .in{max-width:1180px;margin:auto;padding:12px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:850;font-size:17px;color:#fff}.brand img{height:32px;max-width:120px;object-fit:contain;border-radius:6px;background:#fff1}
.top nav{display:flex;gap:4px;flex-wrap:wrap;margin-inline-start:auto}.top nav a,.top nav button{color:#cbd5e1;padding:7px 11px;border-radius:9px;background:none;border:0;font:inherit;cursor:pointer}.top nav a.on,.top nav a:hover,.top nav button:hover{background:#1e2a3d;color:#fff;text-decoration:none}
.wrap{max-width:1180px;margin:22px auto;padding:0 20px}
.sub{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}.sub a{padding:8px 13px;border-radius:10px;background:#fff;border:1px solid var(--line);color:var(--ink);font-weight:700}.sub a.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 14px}h2{font-size:16px;margin:0 0 12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}.stat b{display:block;font-size:26px;margin-top:4px}.stat span{color:var(--muted);font-size:12px}
table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:right;vertical-align:top}th{font-size:12px;color:var(--muted);font-weight:700;background:#fafbfc}
.tbl{overflow-x:auto}
.btn{display:inline-block;border:1px solid #d5dbe4;background:#fff;border-radius:9px;padding:8px 13px;font-weight:700;cursor:pointer;color:var(--ink);font:inherit;font-weight:700}.btn:hover{text-decoration:none}.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}.btn.danger{color:var(--danger)}.btn.sm{padding:4px 9px;font-size:12px}
.field{margin:12px 0}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}.field input[type=text],.field input[type=search],.field textarea,.field select{width:100%;padding:10px 12px;border:1px solid #d5dbe4;border-radius:10px;font:inherit;background:#fff}.field textarea{min-height:110px;resize:vertical}.help{font-size:12px;color:var(--muted);margin-top:5px;line-height:1.8}
.check{display:flex;gap:8px;align-items:center;margin:10px 0;font-weight:700}
.ok{padding:10px 12px;border-radius:10px;background:#ecfdf5;color:#047857;margin-bottom:14px;line-height:1.8}.err{padding:10px 12px;border-radius:10px;background:#fff1f2;color:#be123c;margin-bottom:14px;line-height:1.8}
.pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:700;background:#eef2f7;color:#334155}.pill.g{background:#e7f7ef;color:var(--ok)}.pill.y{background:#fff4d6;color:var(--warn)}.pill.r{background:#fdecec;color:var(--danger)}.pill.b{background:#e8eefc;color:var(--blue)}
.muted{color:var(--muted)}.ltr{direction:ltr;unicode-bidi:embed}
.msg{border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:10px 0;background:#fff;white-space:pre-wrap;line-height:1.9}.msg.staff{background:#f2f6ff;border-color:#d7e2fb}.msg .meta{font-size:12px;color:var(--muted);margin-bottom:6px;white-space:normal}
.bars{display:flex;align-items:flex-end;gap:6px;height:120px;padding-top:10px}.bars div{flex:1;background:#dbe4f5;border-radius:6px 6px 0 0;position:relative;min-height:3px}.bars div span{position:absolute;top:-18px;left:0;right:0;text-align:center;font-size:11px;color:var(--muted)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
</style>
</head>
<body>
<header class="top"><div class="in">
<a class="brand" href="{{ route('planner.app') }}">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@endif{{ $brand['app_name'] }}</a>
<nav>
<a href="{{ route('planner.app') }}">Planner</a>
@if($brand['support_enabled'] || auth()->user()->is_admin)<a href="{{ route('support.index') }}" class="{{ request()->routeIs('support.*') ? 'on' : '' }}">پشتیبانی</a>@endif
<a href="{{ route('account.settings') }}">حساب و تلگرام</a>
@if(auth()->user()->is_admin)<a href="{{ route('admin.home') }}" class="{{ request()->routeIs('admin.*') ? 'on' : '' }}">مدیریت</a>@endif
<form method="post" action="{{ route('logout') }}" style="display:inline">@csrf<button>خروج</button></form>
</nav>
</div></header>
<main class="wrap">
@if(request()->routeIs('admin.*'))
<div class="sub">
<a href="{{ route('admin.home') }}" class="{{ request()->routeIs('admin.home') ? 'on' : '' }}">داشبورد</a>
<a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users*') ? 'on' : '' }}">کاربران</a>
<a href="{{ route('admin.support') }}" class="{{ request()->routeIs('admin.support*') ? 'on' : '' }}">تیکت‌ها</a>
<a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings*') ? 'on' : '' }}">تنظیمات و لوگو</a>
<a href="{{ route('admin.access') }}" class="{{ request()->routeIs('admin.access') ? 'on' : '' }}">ساخت کاربر و API Token</a>
</div>
@endif
@if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
@if($errors->any())<div class="err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
@yield('content')
</main>
</body></html>
