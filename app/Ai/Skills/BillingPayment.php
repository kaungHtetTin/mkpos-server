<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class BillingPayment
{
    public static function instructions(): string
    {
        return SkillCatalog::text('billing-payment');
    }
}
