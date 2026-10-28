<?php

namespace App\Services\AgentSkills;

use App\Services\AiPageLinks;
use Illuminate\Support\Facades\DB;

/** The management model can inspect this catalog only, never business records or credentials. */
class ManagementContext
{
    public array $skills = [];
    public array $agents = [];

    public function metadata(): array
    {
        $references = ['get_wyne_wyne_skills', 'get_customer_service_skills', 'get_sales_skills'];
        foreach (DB::table('ai_skills as s')->join('ai_skill_versions as v', 'v.id', '=', 's.published_version_id')->pluck('v.content') as $json) {
            $references = [...$references, ...(json_decode($json, true)['tools'] ?? [])];
        }
        return ['agents' => DB::table('ai_agents')->orderBy('id')->get(['key', 'name']),
            'tool_references' => array_values(array_unique($references)),
            'modules' => array_values(array_unique([...config('mkpos.enabled_modules', []), ...array_column(AiPageLinks::pages(), 'module')])),
            'screens' => array_keys(AiPageLinks::pages()),
            'roles' => ['owner', 'staff', 'customer', 'internal'],
            'channels' => ['business_web', 'business_mobile', 'shop', 'telegram_private', 'telegram_group'],
            'contexts' => ['image', 'follow_up', 'owner_access', 'staff_access', 'restricted_access']];
    }

    public function catalog(string $search = '', int $offset = 0): array
    {
        $query = DB::table('ai_skills');
        if ($search !== '') $query->where(fn ($q) => $q->where('slug', 'like', '%'.$search.'%')->orWhere('draft', 'like', '%'.$search.'%'));
        $total = (clone $query)->count();
        $items = $query->orderBy('id')->offset($offset)->limit(30)->get()->map(function ($row) {
            $draft = json_decode($row->draft, true);
            return ['id' => $row->id, 'slug' => $row->slug, 'title' => $draft['title'], 'description' => $draft['description'] ?? '', 'status' => $row->status, 'required' => (bool) $row->is_required];
        });
        return ['items' => $items, 'total' => $total, 'next_offset' => $offset + $items->count() < $total ? $offset + $items->count() : null];
    }

    public function skill(int $id): array
    {
        $row = DB::table('ai_skills')->find($id);
        abort_unless($row, 404, 'Skill unavailable.');
        $this->skills[$id] = (int) $row->revision;
        $version = $row->published_version_id ? DB::table('ai_skill_versions')->find($row->published_version_id) : null;
        return ['id' => $id, 'slug' => $row->slug, 'revision' => $row->revision, 'status' => $row->status, 'required' => (bool) $row->is_required,
            'draft' => json_decode($row->draft, true), 'published' => $version ? json_decode($version->content, true) : null,
            'versions' => DB::table('ai_skill_versions')->where('skill_id', $id)->orderByDesc('number')->limit(20)->get(['id', 'number', 'note'])];
    }

    public function version(int $skill, int $id): array
    {
        $this->skill($skill);
        $row = DB::table('ai_skill_versions')->where('skill_id', $skill)->where('id', $id)->first();
        abort_unless($row, 404, 'Version unavailable.');
        return ['id' => $id, 'number' => $row->number, 'content' => json_decode($row->content, true)];
    }

    public function assignments(string $key): array
    {
        $agent = DB::table('ai_agents')->where('key', $key)->first();
        abort_unless($agent, 404, 'Agent unavailable.');
        $this->agents[$key] = (int) $agent->revision;
        $items = DB::table('ai_agent_skill_assignments')->where('agent_id', $agent->id)->orderBy('position')->get()->map(function ($row) {
            $this->skill((int) $row->skill_id);
            return ['skill_id' => $row->skill_id, 'mode' => $row->mode, 'position' => $row->position, 'enabled' => (bool) $row->enabled, 'conditions' => json_decode($row->conditions, true)];
        });
        return ['agent' => $key, 'revision' => $agent->revision, 'items' => $items];
    }

    public function baseline(): array
    {
        return ['skills' => $this->skills, 'agents' => $this->agents];
    }
}
