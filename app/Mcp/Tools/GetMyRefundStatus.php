<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMyRefundStatus extends SupportTool
{
    protected string $name = 'get_my_refund_status';

    protected string $description = 'Get the linked business owner subscription refund request status by refund_id.';
}
