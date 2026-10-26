<?php

return [
    'enabled' => env('TELEGRAM_SUPPORT_ENABLED', false),
    'bot_token' => env('TELEGRAM_SUPPORT_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_SUPPORT_WEBHOOK_SECRET'),
    'webhook_url' => env('TELEGRAM_SUPPORT_WEBHOOK_URL'),
    'daily_limit' => (int) env('TELEGRAM_SUPPORT_DAILY_LIMIT', 200),
    'per_chat_daily_limit' => (int) env('TELEGRAM_SUPPORT_CHAT_DAILY_LIMIT', 20),
    'per_chat_minute_limit' => 4,
    'max_pending_per_chat' => 3,
    'history_days' => 30,
    'max_input_chars' => 2000,
    'max_tokens' => 900,
    'timeout' => 60,
];
