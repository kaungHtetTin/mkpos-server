<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetPendingRefundRequests extends SupportTool
{
    protected string $name = 'get_pending_refund_requests';

    protected string $description = 'Admin only: list subscription refunds awaiting review or transfer with bounded pagination.';
}
