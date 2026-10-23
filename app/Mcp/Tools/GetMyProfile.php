<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMyProfile extends SupportTool
{
    protected string $name = 'get_my_profile';

    protected string $description = 'Read the linked owner or Shop customer profile. No passwords, tokens or other customer records.';
}
