<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class ErrorSupport
{
    public static function instructions(): string
    {
        return SkillCatalog::text('error-support');
    }

    public static function guide(): array
    {
        return ['skill' => 'error_support', 'steps' => preg_split('/\\R/u', SkillCatalog::text('error-support-guide'), flags: PREG_SPLIT_NO_EMPTY)];
    }
}
