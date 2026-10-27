<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class ProcessRefundRequest extends SupportTool
{
    protected string $name = 'process_refund_request';

    protected string $description = 'Admin only: prepare refund approval/rejection or recording of a completed external transfer for human review in Office. No refund record changes until an administrator approves in Office. Does not initiate bank transfers or alter subscription entitlement. Requires confirmed and idempotency_key. Share the returned Office link and pending status.';
}
