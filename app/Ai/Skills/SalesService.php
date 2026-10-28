<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class SalesService
{
    public static function instructions(): string
    {
        return SkillCatalog::text('sales-service');
    }
}
