<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetTroubleshootingGuide extends SupportTool
{
    protected string $name = 'get_troubleshooting_guide';

    protected string $description = 'Get safe troubleshooting steps for login, checkout, 429, printer, offline, barcode, notifications, permissions, product_form, cart, address, orders, chat, language or updates.';
}
