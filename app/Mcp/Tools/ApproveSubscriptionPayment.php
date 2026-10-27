<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class ApproveSubscriptionPayment extends SupportTool
{
    protected string $name = 'approve_subscription_payment';

    protected string $description = 'Admin only: prepare a subscription payment approval for human review in Office. Does not activate access or record payment until an administrator approves in Office and independently verifies funds. Requires price_paid, admin_note, funds_verified, confirmed and idempotency_key. Share the returned Office link and pending status.';
}
