<?php

return [
    'enabled' => (bool) env('MKPOS_AI_ENABLED', false),
    'model' => env('MKPOS_AI_MODEL', 'deepseek-flash'),
    'daily_limit' => 50,
    'max_tokens' => 1600,
    'timeout' => 40,
];
