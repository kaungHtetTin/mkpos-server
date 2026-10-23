<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetSupportedDevices extends SupportTool
{
    protected string $name = 'get_supported_devices';

    protected string $description = 'Get documented browser, Windows, Android and printer compatibility. Never assume a printer model works from its brand alone.';
}
