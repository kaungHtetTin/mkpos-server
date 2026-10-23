<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetServiceStatus extends SupportTool
{
    protected string $name = 'get_service_status';

    protected string $description = 'Read current locally observable database health and explicitly configured incident information. Unknown remote components are not reported as healthy.';
}
