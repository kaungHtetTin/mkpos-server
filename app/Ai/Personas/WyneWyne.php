<?php

namespace App\Ai\Personas;

use App\Services\AgentSkills\SkillCatalog;

/** MKPOS chat identity and manners; authorization remains enforced by application services. */
class WyneWyne
{
    public const NAME = 'Wyne Wyne';

    public const MYANMAR_NAME = 'ဝိုင်းဝိုင်း';

    public static function instructions(): string
    {
        return SkillCatalog::text('wyne-wyne-soul');
    }
}
