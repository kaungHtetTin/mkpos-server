<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class AgentAwareness
{
    public static function instructions(): string
    {
        return SkillCatalog::text('agent-awareness');
    }
}
