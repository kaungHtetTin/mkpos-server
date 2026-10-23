<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class ProcessRefundRequest extends SupportTool
{
    protected string $name = 'process_refund_request';

    protected string $description = 'Admin only: approve/reject a subscription refund, or record an actual completed external transfer with unique transfer_reference and transferred_at. Does not initiate bank transfers or alter subscription entitlement. Requires confirmed and idempotency_key.';
}
