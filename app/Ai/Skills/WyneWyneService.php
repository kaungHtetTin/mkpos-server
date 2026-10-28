<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class WyneWyneService
{
    public static function instructions(): string
    {
        return SkillCatalog::text('wyne-wyne-service');
    }

    public static function guides(): array
    {
        return app(SkillCatalog::class)->session('wyne_wyne', ['role' => 'owner', 'modules' => config('mkpos.enabled_modules', [])])->guides();
    }
}
