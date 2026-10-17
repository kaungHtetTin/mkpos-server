<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset your MKPOS password</title></head>
<body style="margin:0;background:#f4f7f5;font-family:Arial,sans-serif;color:#183c31;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dce7e0;border-radius:12px;">
<tr><td style="padding:24px 28px;background:#0c4a3a;color:#ffffff;font-size:24px;font-weight:bold;border-radius:12px 12px 0 0;">MKPOS <span style="font-size:13px;font-weight:normal;">{{ $audience }}</span></td></tr>
<tr><td style="padding:28px;"><h1 style="font-size:23px;margin:0 0 20px;">Reset your password</h1>
<p>Hello {{ $name }},</p><p style="line-height:1.6;">Enter this one-time code in {{ $audience }} to choose a new password.</p>
<div style="margin:24px 0;padding:20px;text-align:center;background:#eaf5ef;border:1px solid #c7dfd1;border-radius:8px;font-size:34px;letter-spacing:8px;font-weight:bold;">{{ $code }}</div>
<p style="line-height:1.6;">This code expires in <strong>10 minutes</strong>. Only the latest code works. Never share your code with anyone.</p>
<p style="line-height:1.6;color:#61766a;">If you did not request this reset, you can ignore this email. Your password will stay unchanged.</p></td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #e5ece7;color:#61766a;font-size:12px;">MKPOS account security · Please do not reply to this email.</td></tr>
</table></td></tr></table></body></html>
