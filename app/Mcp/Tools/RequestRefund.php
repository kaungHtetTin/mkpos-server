<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class RequestRefund extends SupportTool
{
    protected string $name = 'request_refund';

    protected string $description = 'Request a subscription payment refund for the linked owner. Reserves amount against the original payment; it does not issue money or change subscription access. Requires confirmed and idempotency_key.';
}
