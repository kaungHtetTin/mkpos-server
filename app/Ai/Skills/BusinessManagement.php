<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class BusinessManagement
{
    public static function instructions(): string
    {
        return SkillCatalog::text('business-management');
    }
}
