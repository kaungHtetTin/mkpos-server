<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class BusinessAdvisor
{
    public static function instructions(): string
    {
        return SkillCatalog::text('business-advisor');
    }
}
