<?php

return [
    'lookup_failed' => 'Pending subscription payments could not be checked. Do not report zero pending requests or send an approval alert based on this failed check.',
    'pending_found' => 'Subscription payment requests are awaiting admin approval. Notify MKPOS payments using the existing Telegram tool.',
    'none_pending' => 'No subscription payment requests are awaiting approval. Do not send a payment alert.',
    'no_more_items' => 'No more pending requests on this page. Do not send an empty alert.',
    'yes' => 'Yes',
    'no' => 'No',
    'not_available' => 'Not available',
    'notification_template' => "MKPOS subscription payment — approval needed\nRequest: #{request_id}\nBusiness: {business_name} (#{business_id})\nPackage: {plan_name}\nCurrent plan price (not verified payment): {current_plan_price} {currency} / {duration_days} days\nSubmitted: {requested_at}\nPayment provider at submission: {payment_provider_at_submission}\nRecipient at submission: {recipient_name_at_submission}\nRecipient account at submission: {recipient_account_at_submission}\nScreenshot attached: {screenshot_attached}\nAdmins: review request #{request_id} and its payment evidence in MKPOS Office. Confirm funds before approving. This notification does not approve the request.",
];
