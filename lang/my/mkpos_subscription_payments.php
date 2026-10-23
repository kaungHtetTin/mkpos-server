<?php

return [
    'lookup_failed' => 'Approve စောင့်နေသော subscription payment requests များကို စစ်ဆေး၍ မရသေးပါ။ Request မရှိဟု မပြောပါနှင့်၊ ဤစစ်ဆေးမှုအပေါ် မူတည်ပြီး approval အသိပေးစာ မပို့ပါနှင့်။',
    'pending_found' => 'Admin approve စောင့်နေသော subscription payment requests များ ရှိပါသည်။ လက်ရှိ Telegram tool ဖြင့် MKPOS payments group ကို အသိပေးပါ။',
    'none_pending' => 'Approve စောင့်နေသော subscription payment request မရှိပါ။ Payment အသိပေးစာ မပို့ပါနှင့်။',
    'no_more_items' => 'ဤစာမျက်နှာတွင် နောက်ထပ် pending request မရှိပါ။ အချက်အလက်မပါသော အသိပေးစာ မပို့ပါနှင့်။',
    'yes' => 'ရှိပါသည်',
    'no' => 'မရှိပါ',
    'not_available' => 'မရရှိပါ',
    'notification_template' => "MKPOS subscription payment — approve စစ်ဆေးရန်\nRequest: #{request_id}\nလုပ်ငန်း: {business_name} (#{business_id})\nပက်ကေ့ချ်: {plan_name}\nလက်ရှိပက်ကေ့ချ်ဈေး (ငွေလွှဲပမာဏအတည်ပြုချက်မဟုတ်): {current_plan_price} {currency} / {duration_days} ရက်\nတင်သွင်းချိန်: {requested_at}\nတင်သွင်းစဉ် payment provider: {payment_provider_at_submission}\nတင်သွင်းစဉ် လက်ခံသူ: {recipient_name_at_submission}\nတင်သွင်းစဉ် လက်ခံအကောင့်: {recipient_account_at_submission}\nScreenshot တွဲတင်ထားမှု: {screenshot_attached}\nAdmin များ MKPOS Office တွင် request #{request_id} နှင့် payment အထောက်အထားကို စစ်ဆေးပေးပါ။ ငွေဝင်ကြောင်းအတည်ပြုပြီးမှ approve လုပ်ပါ။ ဤအသိပေးချက်က request ကို approve လုပ်ခြင်း မဟုတ်ပါ။",
];
