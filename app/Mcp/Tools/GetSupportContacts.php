<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetSupportContacts extends SupportTool
{
    protected string $name = 'get_support_contacts';

    protected string $description = 'Get current official support contacts. Do not claim a human was contacted or promise an unconfigured response time.';
}
