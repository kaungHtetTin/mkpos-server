<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMyBillingHistory extends SupportTool
{
    protected string $name = 'get_my_billing_history';

    protected string $description = 'Get authenticated owner billing history with exact amounts and currencies. Do not invent receipt URLs.';
}
