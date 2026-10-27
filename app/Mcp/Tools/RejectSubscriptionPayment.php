<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class RejectSubscriptionPayment extends SupportTool
{
    protected string $name = 'reject_subscription_payment';

    protected string $description = 'Admin only: prepare rejection of a pending subscription request with an explanation. The request remains unchanged until an administrator approves this action in Office. Requires confirmed and idempotency_key. Share the returned Office link and pending status.';
}
