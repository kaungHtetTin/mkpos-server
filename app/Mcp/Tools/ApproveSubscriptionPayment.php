<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class ApproveSubscriptionPayment extends SupportTool
{
    protected string $name = 'approve_subscription_payment';

    protected string $description = 'Admin only: approve a pending subscription request through the existing Office activation and billing workflow. Requires independently verified funds, explicit price_paid, admin_note, confirmed and idempotency_key. A screenshot alone is not verification.';
}
