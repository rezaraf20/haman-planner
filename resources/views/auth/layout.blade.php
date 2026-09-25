<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') | Haman Planner</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Vazirmatn,Inter,system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#0f172a;color:#172033;display:grid;place-items:center;padding:24px 0}
.card{width:min(430px,calc(100% - 32px));background:#fff;border-radius:24px;padding:36px;box-shadow:0 25px 70px rgba(0,0,0,.28)}
.logo{font-size:26px;font-weight:850;letter-spacing:-.5px}.sub{color:#718096;margin:7px 0 24px;line-height:1.8}
.field{margin:16px 0}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}
.field input{width:100%;padding:13px 14px;border:1px solid #d9dee8;border-radius:12px;font-size:15px;outline:none;font-family:inherit}
.field input:focus{border-color:#18212f;box-shadow:0 0 0 3px #e8ebef}.field .help{font-size:12px;color:#94a3b8;margin-top:6px}
.btn{width:100%;border:0;border-radius:12px;padding:13px;background:#18212f;color:#fff;font-weight:800;cursor:pointer;font-size:15px;font-family:inherit}
.row{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:12px 0 18px;font-size:13px}
.remember{display:flex;gap:8px;align-items:center;color:#64748b}
a{color:#18212f;font-weight:700;text-decoration:none}a:hover{text-decoration:underline}
.error{background:#fff1f2;color:#be123c;border:1px solid #fecdd3;padding:10px 12px;border-radius:10px;font-size:13px;line-height:1.8;margin-bottom:6px}
.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;padding:10px 12px;border-radius:10px;font-size:13px;line-height:1.8;margin-bottom:6px}
.foot{text-align:center;font-size:13px;color:#64748b;margin-top:22px}
.hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
</style>
</head>
<body><main class="card">
<div class="logo">Haman Planner</div>
<div class="sub">@yield('subtitle', 'برنامه‌ریزی شخصی و کاری شما')</div>
@if (session('status'))<div class="ok">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
@yield('content')
</main></body></html>
