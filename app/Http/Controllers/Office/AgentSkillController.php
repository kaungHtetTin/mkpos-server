<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\AgentSkills\SkillSession;
use App\Services\AiPageLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgentSkillController extends Controller
{
    private function authorizeOffice(Request $request): object
    {
        $admin = $request->user('office');
        abort_unless($admin && $admin->role === 'super_admin', 403, 'Only a super administrator can manage agent skills.');

        return $admin;
    }

    private function audit(Request $request, string $action, array $details): void
    {
        $admin = $this->authorizeOffice($request);
        DB::table('office_activity_logs')->insert(['admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email,
            'action' => 'AgentSkills@'.$action, 'method' => $request->method(), 'path' => $request->path(), 'details' => json_encode($details), 'created_at' => now()]);
    }

    private function content(Request $request): array
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'category' => 'required|string|max:80', 'description' => 'nullable|string|max:2000',
            'body' => 'required|string|max:60000', 'examples' => 'nullable|string|max:16000', 'tools' => 'present|array|max:100', 'tools.*' => 'string|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'modules' => 'present|array|max:40', 'modules.*' => ['string', Rule::in(array_unique([...array_values(config('mkpos.enabled_modules', [])), ...array_column(AiPageLinks::pages(), 'module')]))],
            'private' => 'required|boolean', 'family' => 'required|in:instruction,workflow,onboarding', 'topic' => 'required|string|max:100|regex:/^[a-z0-9_-]+$/',
            'screen' => 'nullable|string|max:100']);
        $data['description'] = $data['description'] ?? '';
        $data['examples'] = $data['examples'] ?? '';
        if ($data['family'] === 'onboarding') {
            abort_unless(isset(AiPageLinks::pages()[$data['screen'] ?? '']), 422, 'Select a supported screen for an onboarding skill.');
        }
        preg_match_all('/\{\{([a-z_]+)\}\}/', $data['body'], $matches);
        abort_if(array_diff($matches[1], ['language', 'session', 'access_facts', 'assistant_name', 'shop_preferences', 'identity']), 422, 'The instructions contain an unsupported template variable.');

        return $data;
    }

    public function index(Request $request): array
    {
        $this->authorizeOffice($request);
        $request->validate(['q' => 'nullable|string|max:200', 'status' => 'sometimes|in:all,draft,published,archived', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:10,25,50,100']);
        $q = DB::table('ai_skills as s')->leftJoin('ai_skill_versions as v', 'v.id', '=', 's.published_version_id');
        if (($status = $request->query('status', 'all')) !== 'all') {
            $q->where('s.status', $status);
        }
        if ($search = trim($request->query('q', ''))) {
            $q->where(fn ($w) => $w->where('s.slug', 'like', '%'.$search.'%')->orWhere('s.draft', 'like', '%'.$search.'%'));
        }
        $total = $q->count();
        $per = (int) $request->query('per_page', 25);
        $page = min((int) $request->query('page', 1), max(1, (int) ceil($total / $per)));
        $items = $q->orderBy('s.id')->offset(($page - 1) * $per)->limit($per)->get(['s.*', 'v.number as published_version'])->map(function ($row) {
            $draft = json_decode($row->draft, true);

            return ['id' => $row->id, 'slug' => $row->slug, 'status' => $row->status, 'revision' => $row->revision, 'is_required' => (bool) $row->is_required,
                'published_version' => $row->published_version, 'title' => $draft['title'], 'category' => $draft['category'], 'description' => $draft['description'] ?? ''];
        });

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'last_page' => max(1, (int) ceil($total / $per))];
    }

    public function show(Request $request, int $skill): array
    {
        $this->authorizeOffice($request);
        $row = DB::table('ai_skills')->find($skill);
        abort_unless($row, 404);
        $published = $row->published_version_id ? DB::table('ai_skill_versions')->find($row->published_version_id) : null;

        return ['id' => $row->id, 'slug' => $row->slug, 'status' => $row->status, 'revision' => $row->revision, 'is_required' => (bool) $row->is_required,
            'draft' => json_decode($row->draft, true), 'published_version' => $published?->number, 'published' => $published ? json_decode($published->content, true) : null,
            'versions' => DB::table('ai_skill_versions')->where('skill_id', $skill)->orderByDesc('number')->limit(20)->get(['id', 'number', 'note', 'created_at']),
            'versions_next_page' => DB::table('ai_skill_versions')->where('skill_id', $skill)->count() > 20 ? 2 : null];
    }

    public function versions(Request $request, int $skill): array
    {
        $this->authorizeOffice($request);
        abort_unless(DB::table('ai_skills')->where('id', $skill)->exists(), 404);
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);
        $page = (int) ($data['page'] ?? 1);
        $rows = DB::table('ai_skill_versions')->where('skill_id', $skill)->orderByDesc('number')->offset(($page - 1) * 20)->limit(21)->get(['id', 'number', 'note', 'created_at']);

        return ['items' => $rows->take(20)->values(), 'next_page' => $rows->count() > 20 ? $page + 1 : null];
    }

    public function version(Request $request, int $skill, int $version): array
    {
        $this->authorizeOffice($request);
        $row = DB::table('ai_skill_versions')->where('id', $version)->where('skill_id', $skill)->first();
        abort_unless($row, 404);

        return ['text' => SkillSession::render(json_decode($row->content, true)), 'notice' => 'Published version '.$row->number.'. Read-only preview.'];
    }

    public function store(Request $request)
    {
        $this->authorizeOffice($request);
        $content = $this->content($request);
        $slug = $request->validate(['slug' => 'required|string|max:100|regex:/^[a-z0-9_-]+$/|unique:ai_skills,slug'])['slug'];
        $id = DB::transaction(function () use ($request, $content, $slug) {
            $id = DB::table('ai_skills')->insertGetId(['slug' => $slug, 'draft' => json_encode($content), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->audit($request, 'create', ['skill_id' => $id]);

            return $id;
        });

        return response()->json($this->show($request, $id), 201);
    }

    private function locked(Request $request, int $skill): object
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $row = DB::table('ai_skills')->where('id', $skill)->lockForUpdate()->first();
        abort_unless($row, 404);
        abort_if((int) $row->revision !== $data['revision'], 409, 'This skill changed. Reload it before saving.');

        return $row;
    }

    public function update(Request $request, int $skill): array
    {
        $this->authorizeOffice($request);
        $content = $this->content($request);
        DB::transaction(function () use ($request, $skill, $content) {
            $row = $this->locked($request, $skill);
            if ($row->is_required) {
                $original = json_decode($row->draft, true);
                foreach (['family', 'topic', 'private', 'modules', 'screen'] as $key) {
                    abort_if(($content[$key] ?? null) !== ($original[$key] ?? null), 422, 'Base skill applicability is fixed; edit its instructions or examples instead.');
                }
            }
            DB::table('ai_skills')->where('id', $skill)->update(['draft' => json_encode($content), 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
            $this->audit($request, 'save_draft', ['skill_id' => $skill]);
        });

        return $this->show($request, $skill);
    }

    private function uniqueTopics(array $items, ?int $changedSkill = null, ?array $changedContent = null): void
    {
        $topics = [];
        foreach ($items as $item) {
            $item = (array) $item;
            if (! $item['enabled'] || $item['mode'] !== 'on_demand') {
                continue;
            }
            $skill = DB::table('ai_skills')->where('id', $item['skill_id'])->first();
            if (! $skill || ($skill->status !== 'published' && $skill->id !== $changedSkill)) {
                continue;
            }
            $content = $skill->id === $changedSkill ? $changedContent : json_decode(DB::table('ai_skill_versions')->where('id', $skill->published_version_id)->value('content'), true);
            $key = (($content['family'] ?? 'workflow') === 'onboarding' ? 'onboarding' : 'workflow').':'.($content['topic'] ?? $skill->slug);
            abort_if(isset($topics[$key]), 422, 'Two enabled skills use the same lookup topic: '.$key);
            $topics[$key] = true;
        }
    }

    private function publishContent(Request $request, object $row, array $content, string $note): void
    {
        foreach (DB::table('ai_agent_skill_assignments')->where('skill_id', $row->id)->pluck('agent_id')->sort() as $agentId) {
            DB::table('ai_agents')->where('id', $agentId)->lockForUpdate()->first();
            $this->uniqueTopics(DB::table('ai_agent_skill_assignments')->where('agent_id', $agentId)->get()->all(), $row->id, $content);
        }
        $number = (int) DB::table('ai_skill_versions')->where('skill_id', $row->id)->max('number') + 1;
        $version = DB::table('ai_skill_versions')->insertGetId(['skill_id' => $row->id, 'number' => $number, 'content' => json_encode($content),
            'created_by' => $this->authorizeOffice($request)->id, 'note' => $note, 'created_at' => now('UTC')]);
        DB::table('ai_skills')->where('id', $row->id)->update(['published_version_id' => $version, 'draft' => json_encode($content), 'status' => 'published', 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
        $this->audit($request, 'publish', ['skill_id' => $row->id, 'version' => $number, 'note' => $note]);
    }

    public function publish(Request $request, int $skill): array
    {
        $this->authorizeOffice($request);
        $request->validate(['note' => 'nullable|string|max:500']);
        DB::transaction(function () use ($request, $skill) {
            $row = $this->locked($request, $skill);
            $this->publishContent($request, $row, json_decode($row->draft, true), $request->input('note', ''));
        });

        return $this->show($request, $skill);
    }

    public function restore(Request $request, int $skill, int $version): array
    {
        $this->authorizeOffice($request);
        DB::transaction(function () use ($request, $skill, $version) {
            $row = $this->locked($request, $skill);
            $old = DB::table('ai_skill_versions')->where('id', $version)->where('skill_id', $skill)->first();
            abort_unless($old, 404);
            $this->publishContent($request, $row, json_decode($old->content, true), 'Restored version '.$old->number);
        });

        return $this->show($request, $skill);
    }

    public function destroy(Request $request, int $skill): array
    {
        $this->authorizeOffice($request);
        DB::transaction(function () use ($request, $skill) {
            $row = $this->locked($request, $skill);
            abort_if($row->is_required, 422, 'Required base skills can be edited, but cannot be archived.');
            DB::table('ai_skills')->where('id', $skill)->update(['status' => 'archived', 'revision' => DB::raw('revision + 1'), 'updated_at' => now('UTC')]);
            $this->audit($request, 'archive', ['skill_id' => $skill]);
        });

        return ['ok' => true];
    }

    public function preview(Request $request): array
    {
        $this->authorizeOffice($request);
        $content = $this->content($request);

        return ['text' => SkillSession::render($content, ['language' => 'en', 'session' => '[Authenticated session context]', 'access_facts' => '[Server access facts]',
            'assistant_name' => '[Shop assistant name]', 'shop_preferences' => '[Shop preferences]', 'identity' => '[Calling agent identity]']),
            'notice' => 'Preview only. No model request, customer data access or business action was executed.'];
    }

    public function agents(Request $request): array
    {
        $this->authorizeOffice($request);

        return ['items' => DB::table('ai_agents')->orderBy('id')->get()];
    }

    public function assignments(Request $request, string $agent): array
    {
        $this->authorizeOffice($request);
        $row = DB::table('ai_agents')->where('key', $agent)->first();
        abort_unless($row, 404);
        $items = DB::table('ai_agent_skill_assignments')->where('agent_id', $row->id)->orderBy('position')->get()->map(function ($a) {
            $a->conditions = json_decode($a->conditions, true);
            $a->enabled = (bool) $a->enabled;

            return $a;
        });

        return ['agent' => $row, 'items' => $items, 'skills' => DB::table('ai_skills')->where('status', '!=', 'archived')->orderBy('slug')->get(['id', 'slug', 'is_required', 'status'])];
    }

    public function saveAssignments(Request $request, string $agent): array
    {
        $this->authorizeOffice($request);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'items' => 'present|array|max:300', 'items.*.skill_id' => 'required|integer|distinct|exists:ai_skills,id',
            'items.*.mode' => 'required|in:always,on_demand', 'items.*.position' => 'required|integer|min:0|max:999999', 'items.*.enabled' => 'required|boolean',
            'items.*.conditions' => 'present|array:roles,channels,contexts', 'items.*.conditions.roles' => 'sometimes|array', 'items.*.conditions.roles.*' => 'in:owner,staff,customer,internal',
            'items.*.conditions.channels' => 'sometimes|array', 'items.*.conditions.channels.*' => 'in:business_web,business_mobile,shop,telegram_private,telegram_group',
            'items.*.conditions.contexts' => 'sometimes|array', 'items.*.conditions.contexts.*' => 'in:image,follow_up,owner_access,staff_access,restricted_access']);
        DB::transaction(function () use ($request, $agent, $data) {
            $row = DB::table('ai_agents')->where('key', $agent)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if((int) $row->revision !== $data['revision'], 409, 'Assignments changed. Reload before saving.');
            $required = DB::table('ai_agent_skill_assignments as a')->join('ai_skills as s', 's.id', '=', 'a.skill_id')->where('a.agent_id', $row->id)->where('s.is_required', true)->get(['a.*']);
            foreach ($required as $old) {
                $next = collect($data['items'])->firstWhere('skill_id', $old->skill_id);
                abort_unless($next && $next['enabled'] && $next['mode'] === $old->mode && json_encode($next['conditions']) === json_encode(json_decode($old->conditions, true)), 422, 'Required base skill assignments cannot be removed, disabled or restricted.');
            }
            foreach ($data['items'] as $item) {
                abort_if(DB::table('ai_skills')->where('id', $item['skill_id'])->where('status', 'archived')->exists(), 422, 'Archived skills cannot be assigned.');
            }
            $this->uniqueTopics($data['items']);
            DB::table('ai_agent_skill_assignments')->where('agent_id', $row->id)->delete();
            foreach ($data['items'] as $item) {
                DB::table('ai_agent_skill_assignments')->insert(['agent_id' => $row->id, 'skill_id' => $item['skill_id'], 'mode' => $item['mode'], 'position' => $item['position'],
                    'enabled' => $item['enabled'], 'conditions' => json_encode($item['conditions']), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }
            DB::table('ai_agents')->where('id',$row->id)->increment('revision');
            $this->audit($request,'assign',['agent' => $agent, 'skill_ids' => array_column($data['items'],'skill_id')]);
        });

        return $this->assignments($request,$agent);
    }
}
