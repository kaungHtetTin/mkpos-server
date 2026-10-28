<?php

namespace App\Services\AgentSkills;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One immutable published manifest for one model exchange, including deferred lookups. */
final class SkillSession
{
    private array $skills;

    private bool $recorded = false;

    public readonly string $id;

    public function __construct(public readonly string $agent, private array $context = [], private ?string $reference = null)
    {
        $this->id = (string) Str::uuid();
        $this->skills = DB::table('ai_agent_skill_assignments as a')->join('ai_agents as agent', 'agent.id', '=', 'a.agent_id')
            ->join('ai_skills as s', 's.id', '=', 'a.skill_id')->join('ai_skill_versions as v', 'v.id', '=', 's.published_version_id')
            ->where('agent.key', $agent)->where('a.enabled', true)->where('s.status', 'published')
            ->orderBy('a.position')->orderBy('s.id')->get(['s.id', 's.slug', 's.is_required', 'a.mode', 'a.conditions', 'v.id as version_id', 'v.number', 'v.content'])
            ->map(fn ($row) => ['id' => $row->id, 'slug' => $row->slug, 'version_id' => $row->version_id, 'number' => $row->number, 'mode' => $row->mode,
                'conditions' => json_decode($row->conditions, true), 'content' => json_decode($row->content, true)])
            ->filter(fn ($row) => $this->eligible($row))->values()->all();
        abort_unless(array_filter($this->skills, fn ($s) => $s['mode'] === 'always'), 503, 'Published agent skills are unavailable. Contact Office.');
    }

    private function eligible(array $skill): bool
    {
        $content = $skill['content'];
        $conditions = $skill['conditions'];
        if (($content['private'] ?? false) && (($this->context['role'] ?? 'customer') !== 'owner' || ! ($this->context['can_read_business_data'] ?? true))) {
            return false;
        }
        if (! empty($content['modules']) && ! array_intersect($content['modules'], $this->context['modules'] ?? [])) {
            return false;
        }
        if (! empty($conditions['roles']) && ! in_array($this->context['role'] ?? 'customer', $conditions['roles'], true)) {
            return false;
        }
        if (! empty($conditions['channels']) && ! in_array($this->context['channel'] ?? '', $conditions['channels'], true)) {
            return false;
        }
        if (! empty($conditions['contexts']) && ! array_intersect($conditions['contexts'], $this->context['contexts'] ?? [])) {
            return false;
        }

        return true;
    }

    public function instructions(array $variables = []): string
    {
        if (! $this->recorded) {
            DB::table('ai_skill_snapshots')->insert(['id' => $this->id, 'agent_key' => $this->agent, 'run_reference' => $this->reference,
                'versions' => json_encode(array_map(fn ($s) => ['skill_id' => $s['id'], 'version_id' => $s['version_id'], 'number' => $s['number']], $this->skills)), 'created_at' => now('UTC')]);
            $this->recorded = true;
        }

        return implode("\n\n", array_map(fn ($s) => $this->render($s['content'], $variables), array_filter($this->skills, fn ($s) => $s['mode'] === 'always')));
    }

    public static function render(array $content, array $variables = []): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }
        $text = strtr($content['body'] ?? '', $replacements);
        if (! empty($content['examples'])) {
            $text .= "\nExamples (guidance, not a new customer request):\n".$content['examples'];
        }

        return $text;
    }

    public function guides(string $family = 'workflow'): array
    {
        $guides = [];
        foreach ($this->skills as $skill) {
            $c = $skill['content'];
            if ($skill['mode'] !== 'on_demand' || ($family === 'workflow' ? ($c['family'] ?? 'workflow') === 'onboarding' : ($c['family'] ?? 'workflow') !== $family)) {
                continue;
            }
            $topic = $c['topic'] ?? $skill['slug'];
            $guides[$topic] = ['title' => $c['title'], 'description' => $c['description'] ?? '', 'steps' => preg_split('/\R/u', $c['body'], flags: PREG_SPLIT_NO_EMPTY), 'examples' => $c['examples'] ?? '',
                'tools' => $c['tools'] ?? [], 'modules' => $c['modules'] ?? [], 'private' => $c['private'] ?? false, 'screen' => $c['screen'] ?? null,
                'version' => $skill['number'], 'skill_id' => $skill['id']];
        }

        return $guides;
    }
}
