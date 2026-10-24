<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ValidateProductImport extends SupportTool
{
    protected string $name = 'validate_product_import';

    protected string $description = 'Read-only validation of actual UTF-8 product CSV text (csv_content), max 5 MB and 5000 product rows. Returns file/row/field errors, duplicate codes inside the file, corrections and paginated previews. Optional filename must end in .csv; price_types declares extra selling-price columns; limit/offset and issues_only paginate results for the same content. Never accept URLs, paths, credentials or business IDs. Does not check private catalog duplicates, save imports or create products. Valid means file checks passed, not imported or fully checked against a business.';
}
