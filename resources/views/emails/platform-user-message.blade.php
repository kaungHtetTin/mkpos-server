<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;background:#f4f7f5;font-family:Arial,sans-serif;color:#183c31;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dce7e0;border-radius:12px;">
<tr><td style="padding:24px 28px;background:#0c4a3a;color:#ffffff;font-size:24px;font-weight:bold;border-radius:12px 12px 0 0;">MKPOS <span style="font-size:13px;font-weight:normal;">{{ $audience ?? 'Shops' }}</span></td></tr>
<tr><td style="padding:28px;"><h1 style="font-size:23px;line-height:1.4;margin:0 0 20px;overflow-wrap:anywhere;">{{ $subject }}</h1><p>Hello {{ $name }},</p><div style="white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.7;">{{ $message }}</div><p style="margin-top:28px;">The MKPOS team</p></td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #e5ece7;color:#61766a;font-size:12px;">A message from MKPOS about your {{ ($audience ?? 'Shops') === 'Business' ? 'business' : 'platform' }} account.</td></tr>
</table></td></tr></table></body></html>
