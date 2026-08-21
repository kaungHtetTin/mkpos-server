<?php

return [
    'enabled_modules' => ['sell', 'products', 'purchases', 'suppliers', 'customers', 'expenses', 'transactions', 'reports', 'billing', 'settings'],
    'theme_name' => 'mkpos-green',
    'receipt_template_name' => 'standard-80mm',
    'report_template_name' => 'standard',
    'default_payment_methods' => ['Cash', 'Wallet Pay', 'Banking Pay', 'KPay', 'Wave Pay', 'Credit'],
    'trial' => [
        'enabled' => filter_var(env('TRIAL_ENABLED', env('MKPOS_TRIAL_ENABLED', true)), FILTER_VALIDATE_BOOL),
        'plan_slug' => env('TRIAL_PLAN_SLUG', env('MKPOS_TRIAL_PLAN_SLUG', 'free-trial')),
        'duration_months' => max(1, (int) env('TRIAL_MONTHS', env('MKPOS_TRIAL_MONTHS', 1))),
        'offline_sync_grace_days' => max(0, (int) env(
            'TRIAL_OFFLINE_SYNC_GRACE_DAYS',
            env('MKPOS_TRIAL_OFFLINE_SYNC_GRACE_DAYS', 7)
        )),
        'monitor_log_channel' => env('TRIAL_MONITOR_LOG_CHANNEL', 'trial_rollout'),
    ],
];
