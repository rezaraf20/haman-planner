<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $subjectLine }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f4f5f7;font-family:{{ $rtl ? 'Vazirmatn,Tahoma,' : '' }}Arial,Helvetica,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:28px;text-align:{{ $rtl ? 'right' : 'left' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<tr><td>
<p style="margin:0 0 18px;font-weight:bold;color:#4f46e5">{{ \App\Support\AppSettings::all()['app_name'] ?? __('common.app_name') }}</p>
<p style="margin:0 0 14px">{{ $greeting }}</p>
@foreach ($lines as $line)
<p style="margin:0 0 12px;line-height:1.8">{{ $line }}</p>
@endforeach
@foreach ($extraLines as $line)
<p style="margin:0 0 8px;line-height:1.8">• {{ $line }}</p>
@endforeach
@if ($cta)
<p style="margin:22px 0"><a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px">{{ $cta }}</a></p>
<p style="margin:0 0 12px;font-size:12px;color:#6b7280;direction:ltr;word-break:break-all">{{ $url }}</p>
@endif
<hr style="border:none;border-top:1px solid #e5e7eb;margin:22px 0">
<p style="margin:0;font-size:12px;color:#6b7280">{{ __('emails.footer') }}</p>
@if ($unsubscribeUrl)
<p style="margin:8px 0 0;font-size:12px"><a href="{{ $unsubscribeUrl }}" style="color:#6b7280">{{ __('emails.unsubscribe') }}</a></p>
@endif
</td></tr></table>
</td></tr></table>
</body>
</html>
