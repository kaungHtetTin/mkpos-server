<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class UpdateMyProfile extends SupportTool
{
    protected string $name = 'update_my_profile';

    protected string $description = 'Update the linked Shop customer name, phone and address, or the linked owner name after profile_write consent with reauthentication in the trusted app. Owner email/password changes stay in the app. Never ask for a password in chat. Requires confirmed and idempotency_key.';
}
