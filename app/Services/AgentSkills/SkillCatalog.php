<?php

namespace App\Services\AgentSkills;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SkillCatalog
{
    public function session(string $agent, array $context = [], ?string $reference = null): SkillSession
    {
        abort_unless(Schema::hasTable('ai_skills'), 503, 'Run the agent skill migration before using AI.');

        return new SkillSession($agent, $context, $reference);
    }

    /** Compatibility for internal image/proof helpers; content always comes from published DB versions. */
    public static function text(string $slug): string
    {
        abort_unless(Schema::hasTable('ai_skills'), 503, 'Published agent skills are unavailable.');
        $row = DB::table('ai_skills as s')->join('ai_skill_versions as v', 'v.id', '=', 's.published_version_id')->where('s.slug', $slug)->where('s.status', 'published')->value('v.content');
        abort_unless($row, 503, 'Required published skill is unavailable: '.$slug);

        return json_decode($row, true, flags: JSON_THROW_ON_ERROR)['body'];
    }
}
