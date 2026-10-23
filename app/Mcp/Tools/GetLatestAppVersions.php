<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetLatestAppVersions extends SupportTool
{
    protected string $name = 'get_latest_app_versions';

    protected string $description = 'Check published app versions and release notes, not local build files. Compare with the user reported version without inventing update availability.';
}
