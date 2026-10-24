<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetProductImportTemplate extends SupportTool
{
    protected string $name = 'get_product_import_template';

    protected string $description = 'Return a UTF-8 MKPOS product-import CSV template, a valid example and field rules. Optional price_types is a comma-separated list of additional selling-price names supplied by the user, not private business configuration. Does not read a catalog, create products or send a Telegram file.';
}
