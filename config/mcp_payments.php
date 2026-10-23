<?php

return [
    // Server-to-server secret for OpenClaw. Never expose it to customers.
    'token' => env('MKPOS_PAYMENTS_MCP_TOKEN'),
];
