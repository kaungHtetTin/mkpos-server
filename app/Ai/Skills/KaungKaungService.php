<?php

namespace App\Ai\Skills;

use App\Services\AgentSkills\SkillCatalog;

class KaungKaungService
{
    public const NAME = 'Kaung Kaung';

    public static function instructions(): string
    {
        return SkillCatalog::text('kaung-kaung-service');
    }

    public static function guides(): array
    {
        return app(SkillCatalog::class)->session('kaung_kaung', ['role' => 'customer', 'modules' => config('mkpos.enabled_modules', [])])->guides();
    }
}
