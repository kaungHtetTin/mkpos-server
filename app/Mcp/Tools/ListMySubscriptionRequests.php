<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListMySubscriptionRequests extends SupportTool
{
    protected string $name = 'list_my_subscription_requests';

    protected string $description = 'List the authenticated owner subscription requests with bounded pagination. No private proof paths or internal admin notes.';
}
