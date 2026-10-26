<?php

return [
    // Current receipt checks run in the request. Force monitoring only for
    // deployments retaining the legacy worker; queued legacy work also requires it.
    'payment_validation_required' => env('WORKER_HEALTH_PAYMENT_VALIDATION_REQUIRED', false),
    'queue_stale_seconds' => 45,
    'periodic_stale_seconds' => 180,
    'backlog_stale_seconds' => 600,
];
