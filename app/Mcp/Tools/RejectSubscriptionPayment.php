<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class RejectSubscriptionPayment extends SupportTool
{
    protected string $name = 'reject_subscription_payment';

    protected string $description = 'Admin only: reject a pending subscription request with an explanation. Requires confirmed and idempotency_key.';
}
