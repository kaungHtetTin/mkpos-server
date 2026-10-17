<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your MKPOS subscription is approved</title></head>
<body style="margin:0;background:#f4f7f5;font-family:Arial,sans-serif;color:#183c31;">
<div style="display:none;max-height:0;overflow:hidden;">Office has approved your {{ $details['plan_name'] }} subscription for {{ $details['business_name'] }}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dce7e0;border-radius:12px;">
<tr><td style="padding:24px;background:#0c4a3a;color:#ffffff;font-size:24px;font-weight:bold;border-radius:12px 12px 0 0;">MKPOS <span style="font-size:13px;font-weight:normal;">Business</span></td></tr>
<tr><td style="padding:24px;line-height:1.7;overflow-wrap:anywhere;">
<p style="margin:0 0 12px;color:#147357;font-size:12px;font-weight:bold;letter-spacing:1px;">SUBSCRIPTION APPROVED</p>
<h1 style="font-size:24px;line-height:1.35;margin:0 0 20px;">Your plan is ready</h1>
<p>Hello {{ $name }},</p>
<p>Our office has approved your subscription payment request for <strong>{{ $details['business_name'] }}</strong>. Thank you for choosing MKPOS.</p>
<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dce7e0;border-radius:8px;font-size:14px;table-layout:fixed;">
@foreach (['Plan' => $details['plan_name'], 'Amount paid' => number_format($details['amount_paid']).' '.$details['currency'], 'Access starts' => $details['starts_at'], 'Access until' => $details['ends_at'], 'Approved on' => $details['approved_at'], 'Request reference' => $details['reference']] as $label => $value)
<tr><th scope="row" width="38%" style="padding:10px 12px;text-align:left;font-weight:normal;vertical-align:top;color:#61766a;border-bottom:1px solid #e5ece7;">{{ $label }}</th><td style="padding:10px 12px;vertical-align:top;font-weight:bold;overflow-wrap:anywhere;border-bottom:1px solid #e5ece7;">{{ $value }}</td></tr>
@endforeach
</table>
<p style="margin-top:24px;">Open MKPOS and go to <strong>Billing</strong> to view your plan and payment history. If this approval renews your current plan, the access end date above includes the added subscription period.</p>
<p>Thank you,<br><strong>The MKPOS team</strong></p>
</td></tr>
<tr><td style="padding:18px 24px;border-top:1px solid #e5ece7;color:#61766a;font-size:12px;line-height:1.6;">This is an automatic confirmation about your MKPOS business subscription. Keep this email for your records.</td></tr>
</table></td></tr></table></body></html>
