<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMySubscriptionRequest extends SupportTool
{
    protected string $name = 'get_my_subscription_request';

    protected string $description = 'Get an authenticated owner subscription request by request_id; other businesses are inaccessible.';
}
