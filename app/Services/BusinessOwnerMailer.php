<?php

namespace App\Services;

class BusinessOwnerMailer extends PlatformUserMailer
{
    protected string $audience = 'Business';
}
