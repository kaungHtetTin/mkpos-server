<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMySubscriptionStatus extends SupportTool
{
    protected string $name = 'get_my_subscription_status';

    protected string $description = 'Get the authenticated business owner subscription status. Requires linked owner identity; never accepts business_id.';
}
