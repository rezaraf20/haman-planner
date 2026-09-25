@extends('layouts.page')
@section('title', 'تنظیمات')
@section('content')
<h1>تنظیمات پلتفرم</h1>
<form method="post" action="{{ route('admin.settings.save') }}" enctype="multipart/form-data">
@csrf
<div class="card">
<h2>برند و لوگو</h2>
<div class="field"><label>نام پلتفرم</label><input type="text" name="app_name" value="{{ old('app_name', $s['app_name']) }}" maxlength="60" required></div>
<div class="field"><label>شعار / توضیح کوتاه</label><input type="text" name="app_tagline" value="{{ old('app_tagline', $s['app_tagline']) }}" maxlength="120"><div class="help">زیر نام در صفحه ورود و منوی داشبورد نمایش داده می‌شود.</div></div>
<div class="field"><label>لوگو</label>
@if($s['logo'])<div class="row" style="margin-bottom:8px"><img src="{{ $s['logo'] }}" alt="" style="height:56px;max-width:200px;object-fit:contain;border:1px solid var(--line);border-radius:10px;padding:4px;background:#fff"><label class="check"><input type="checkbox" name="remove_logo" value="1"> حذف لوگو</label></div>@endif
<input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
<div class="help">PNG، JPG یا WEBP، حداکثر ۳۰۰ کیلوبایت. پیشنهاد: تصویر افقی با ارتفاع حدود ۶۴ پیکسل و پس‌زمینه‌ی شفاف.</div></div>
</div>
<div class="card">
<h2>ثبت‌نام و پشتیبانی</h2>
<label class="check"><input type="checkbox" name="registration_enabled" value="1" @checked($s['registration_enabled'])> ثبت‌نام عمومی باز باشد</label>
<label class="check"><input type="checkbox" name="support_enabled" value="1" @checked($s['support_enabled'])> بخش پشتیبانی برای کاربران فعال باشد</label>
<div class="field"><label>متن راهنمای پشتیبانی</label><textarea name="support_note" maxlength="2000" placeholder="مثلاً: ساعات پاسخگویی، راه‌های تماس، یا توضیح اشتراک و هزینه‌ها">{{ old('support_note', $s['support_note']) }}</textarea><div class="help">بالای صفحه‌ی پشتیبانی کاربران نمایش داده می‌شود.</div></div>
</div>
<div class="card">
<h2>اطلاعیه</h2>
<div class="field"><label>متن اطلاعیه برای همه کاربران</label><textarea name="announcement" maxlength="500" placeholder="خالی = بدون اطلاعیه">{{ old('announcement', $s['announcement']) }}</textarea><div class="help">به‌صورت نوار بالای داشبورد Planner به همه‌ی کاربران نمایش داده می‌شود.</div></div>
</div>
<button class="btn primary">ذخیره تنظیمات</button>
</form>
@endsection
