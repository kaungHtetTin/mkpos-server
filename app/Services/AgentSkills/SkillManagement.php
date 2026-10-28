<?php

namespace App\Services\AgentSkills;

use App\Ai\Agents\SkillManager;
use App\Http\Controllers\Office\AgentSkillController;
use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use App\Services\AiProviderCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SkillManagement
{
    private const TYPES = ['create_skill', 'update_skill', 'publish_skill', 'archive_skill', 'restore_skill', 'assign_skills'];

    public function prepare(object $admin, array $input): array
    {
        $lock = Cache::lock('skill-management:'.$admin->id, 180);
        abort_unless($lock->get(), 409, 'An AI skill proposal is already being prepared.');
        try {
            if ($old = DB::table('ai_skill_management_proposals')->find($input['request_id'])) {
                abort_unless((int) $old->admin_id === (int) $admin->id && $old->instruction === $input['instruction'] && json_decode($old->context, true) === $input['context'], 409, 'Use a new request ID for a different instruction.');
                abort_if($old->status === 'processing', 409, 'This proposal is still being prepared.');
                return $this->view($old);
            }
            $settings = app(AiAssistantSettings::class)->get();
            abort_unless($settings['enabled'] && $settings['configured'], 503, 'Enable and configure AI in Office AI assistant settings first.');
            $book = app(AiBalanceBook::class);
            $book->funded();
            $pricing = $book->pricing('deepseek-flash');
            $context = new ManagementContext;
            $supplied = ['metadata' => $context->metadata(), 'catalog' => $context->catalog(), 'editor' => $input['context']];
            if (! empty($input['context']['skill_id'])) $supplied['skill'] = $context->skill($input['context']['skill_id']);
            if (! empty($input['context']['agent'])) $supplied['assignments'] = $context->assignments($input['context']['agent']);
            DB::table('ai_skill_management_proposals')->insert(['id' => $input['request_id'], 'admin_id' => $admin->id, 'instruction' => $input['instruction'], 'context' => json_encode($input['context']),
                'status' => 'processing', 'expires_at' => now('UTC')->addHour(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            try {
                app(AiProviderCredentials::class)->activate();
                $answer = (new SkillManager($context))->prompt(json_encode(['administrator_request' => $input['instruction'], 'catalog_context' => $supplied], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), provider: 'deepseek', model: 'deepseek-flash', timeout: 90);
                // Account for the model request even if its proposed actions fail validation.
                DB::transaction(function () use ($book, $input, $admin, $answer, $pricing) {
                    $cost = $book->chargeSystem($input['request_id'], $admin, 'deepseek-flash', $answer->usage->inputTokens, $answer->usage->outputTokens, $answer->usage->cacheReadInputTokens, $pricing, 'Office AI skill management');
                    DB::table('ai_skill_management_proposals')->where('id', $input['request_id'])->update(['estimated_cost_usd' => $cost]);
                });
                $data = validator($answer->toArray(), ['summary' => 'required|string|max:4000', 'findings' => 'present|array|max:20', 'findings.*' => 'string|max:2000',
                    'test_cases' => 'present|array|max:10', 'test_cases.*.request' => 'required|string|max:2000', 'test_cases.*.expected' => 'required|string|max:2000', 'actions_json' => 'required|string|max:160000'])->validate();
                $actions = json_decode($data['actions_json'], true, flags: JSON_THROW_ON_ERROR);
                $actions = $this->actions($actions, $context);
                $baseline = $context->baseline();
                try {
                    DB::transaction(function () use ($admin, $actions, $baseline) {
                        $this->checkBaseline($baseline);
                        throw new ProposalPreview($this->execute($admin, $actions)['review']);
                    });
                } catch (ProposalPreview $preview) {
                    $proposal = ['summary' => $data['summary'], 'findings' => $data['findings'], 'test_cases' => $data['test_cases'], 'actions' => $actions, 'review' => $preview->review,
                        'affects_live_agents' => (bool) array_filter($actions, fn ($a) => in_array($a['type'], ['publish_skill', 'archive_skill', 'restore_skill', 'assign_skills']))];
                }
                DB::table('ai_skill_management_proposals')->where('id', $input['request_id'])->update(['status' => 'ready', 'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR), 'baseline' => json_encode($baseline), 'updated_at' => now('UTC')]);
                $this->audit($admin, 'ai_propose', $input['request_id']);
            } catch (\Throwable $error) {
                DB::table('ai_skill_management_proposals')->where('id', $input['request_id'])->update(['status' => 'failed', 'updated_at' => now('UTC')]);
                if ($error instanceof ValidationException || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) throw $error;
                Log::warning('Skill management proposal failed', ['exception_class' => get_class($error)]);
                abort(503, 'The AI could not prepare a valid proposal. Try a more specific instruction.');
            }
            return $this->get($admin, $input['request_id']);
        } finally { $lock->release(); }
    }

    private function actions(mixed $actions, ManagementContext $context): array
    {
        abort_unless(is_array($actions) && array_is_list($actions) && count($actions) <= 12, 422, 'The AI must propose a list of at most 12 supported changes.');
        $refs = []; $changed = []; $agents = [];
        $knownTools = $context->metadata()['tool_references'];
        foreach ($actions as &$action) {
            abort_unless(is_array($action) && in_array($action['type'] ?? '', self::TYPES, true), 422, 'Unsupported skill management action.');
            $fields = match ($action['type']) {
                'create_skill' => ['type', 'ref', 'slug', 'content'], 'update_skill' => ['type', 'skill_id', 'content'],
                'publish_skill' => ['type', 'skill_id', 'ref'], 'archive_skill' => ['type', 'skill_id'],
                'restore_skill' => ['type', 'skill_id', 'version_id'], 'assign_skills' => ['type', 'agent', 'changes'],
            };
            abort_if(array_diff(array_keys($action), $fields), 422, 'Unsupported proposal fields.');
            if ($action['type'] === 'create_skill') {
                validator($action, ['ref' => 'required|string|regex:/^[a-z][a-z0-9_]{0,39}$/', 'slug' => 'required|string|max:100|regex:/^[a-z0-9_-]+$/'])->validate();
                abort_if(isset($refs[$action['ref']]), 422, 'Duplicate new skill reference.');
                $refs[$action['ref']] = true;
            } elseif ($action['type'] === 'assign_skills') {
                $agent = $action['agent'] ?? '';
                abort_unless(isset($context->agents[$agent]) && ! isset($agents[$agent]), 422, 'Read current agent assignments before proposing one patch per agent.');
                $agents[$agent] = true;
                validator($action, ['changes' => 'required|array|min:1|max:100', 'changes.*.mode' => 'sometimes|in:always,on_demand', 'changes.*.remove' => 'sometimes|boolean'])->validate();
                $targets = [];
                foreach ($action['changes'] as $change) {
                    abort_if(! is_array($change) || array_diff(array_keys($change), ['skill_id', 'ref', 'mode', 'position', 'enabled', 'conditions', 'remove']), 422, 'Unsupported assignment fields.');
                    $this->target($change, $context, $refs);
                    $key = isset($change['ref']) ? 'ref:'.$change['ref'] : 'id:'.$change['skill_id'];
                    abort_if(isset($targets[$key]), 422, 'Duplicate assignment change.');
                    $targets[$key] = true;
                }
            } else {
                $this->target($action, $context, $action['type'] === 'publish_skill' ? $refs : []);
                $target = $action['skill_id'] ?? $action['ref'];
                $kind = $action['type'] === 'publish_skill' ? 'publish' : 'edit';
                abort_if(isset($changed[$kind.':'.$target]), 422, 'Conflicting changes to the same skill.');
                $changed[$kind.':'.$target] = true;
                if ($action['type'] === 'restore_skill') validator($action, ['version_id' => 'required|integer|min:1'])->validate();
            }
            if (in_array($action['type'], ['create_skill', 'update_skill'])) {
                validator($action, ['content' => 'required|array:title,category,description,body,examples,tools,modules,private,family,topic,screen', 'content.tools' => 'present|array', 'content.tools.*' => 'string|max:100'])->validate();
                abort_if(array_diff($action['content']['tools'], $knownTools), 422, 'The AI referenced an unknown tool. Skills cannot add executable capabilities.');
            }
        }
        return $actions;
    }

    private function target(array $action, ManagementContext $context, array $refs): void
    {
        abort_unless(isset($action['skill_id']) !== isset($action['ref']), 422, 'Specify one skill ID or new reference.');
        if (isset($action['ref'])) {
            abort_unless(is_string($action['ref']) && isset($refs[$action['ref']]), 422, 'Unknown new skill reference.');
        } else {
            validator($action, ['skill_id' => 'required|integer|min:1'])->validate();
            abort_unless(isset($context->skills[$action['skill_id']]), 422, 'Read the current skill before proposing a change.');
        }
    }

    private function checkBaseline(array $baseline): void
    {
        $skills = $baseline['skills']; ksort($skills);
        foreach ($skills as $id => $revision) {
            $row = DB::table('ai_skills')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && (int) $row->revision === (int) $revision, 409, 'A reviewed skill changed. Generate a fresh proposal.');
        }
        $agents = $baseline['agents']; ksort($agents);
        foreach ($agents as $key => $revision) {
            $row = DB::table('ai_agents')->where('key', $key)->lockForUpdate()->first();
            abort_unless($row && (int) $row->revision === (int) $revision, 409, 'Agent assignments changed. Generate a fresh proposal.');
        }
    }

    private function skillState(int $id): array
    {
        $row = DB::table('ai_skills')->find($id);
        $version = $row->published_version_id ? DB::table('ai_skill_versions')->find($row->published_version_id) : null;
        return ['skill' => $row->slug, 'status' => $row->status, 'draft' => json_decode($row->draft, true), 'published_version' => $version?->number, 'published_content' => $version ? json_decode($version->content, true) : null];
    }

    private function assignmentState(string $key): array
    {
        $context = new ManagementContext;
        $items = $context->assignments($key)['items']->all();
        foreach ($items as &$item) $item['skill'] = DB::table('ai_skills')->where('id', $item['skill_id'])->value('slug');
        return ['agent' => $key, 'assignments' => $items];
    }

    private function execute(object $admin, array $actions): array
    {
        $controller = app(AgentSkillController::class);
        $refs = []; $review = []; $skills = []; $agents = [];
        foreach ($actions as $action) {
            $id = isset($action['ref']) ? ($refs[$action['ref']] ?? null) : ($action['skill_id'] ?? null);
            $before = $action['type'] === 'assign_skills' ? $this->assignmentState($action['agent']) : ($id ? $this->skillState($id) : null);
            $revision = $id ? DB::table('ai_skills')->where('id', $id)->value('revision') : null;
            $request = function (array $body, string $method) use ($admin) {
                $r = Request::create('/api/office/agent-skills/ai', $method, $body);
                $r->setUserResolver(fn () => $admin);
                return $r;
            };
            switch ($action['type']) {
                case 'create_skill':
                    $saved = $controller->store($request($action['content'] + ['slug' => $action['slug']], 'POST'))->getData(true);
                    $id = $refs[$action['ref']] = $saved['id'];
                    break;
                case 'update_skill': $controller->update($request($action['content'] + ['revision' => $revision], 'PUT'), $id); break;
                case 'publish_skill': $controller->publish($request(['revision' => $revision, 'note' => 'Reviewed AI management proposal'], 'POST'), $id); break;
                case 'archive_skill': $controller->destroy($request(['revision' => $revision], 'DELETE'), $id); break;
                case 'restore_skill': $controller->restore($request(['revision' => $revision], 'POST'), $id, $action['version_id']); break;
                case 'assign_skills':
                    $agent = $action['agent'];
                    $current = $controller->assignments($request([], 'GET'), $agent);
                    $items = collect($current['items'])->map(fn ($row) => array_intersect_key((array) $row, array_flip(['skill_id', 'mode', 'position', 'enabled', 'conditions'])))->keyBy('skill_id');
                    foreach ($action['changes'] as $change) {
                        $skillId = isset($change['ref']) ? $refs[$change['ref']] : $change['skill_id'];
                        if ($change['remove'] ?? false) { $items->forget($skillId); continue; }
                        unset($change['ref'], $change['remove']);
                        $items->put($skillId, array_replace($items->get($skillId, ['mode' => 'on_demand', 'position' => 100, 'enabled' => true, 'conditions' => []]), $change, ['skill_id' => $skillId]));
                    }
                    $controller->saveAssignments($request(['revision' => $current['agent']->revision, 'items' => $items->values()->all()], 'PUT'), $agent);
                    $agents[] = $agent;
                    break;
            }
            if ($id) $skills[] = $id;
            $after = $action['type'] === 'assign_skills' ? $this->assignmentState($action['agent']) : $this->skillState($id);
            $review[] = ['action' => $action['type'], 'target' => $after['skill'] ?? $after['agent'], 'before' => $before, 'after' => $after];
        }
        return ['review' => $review, 'skill_ids' => array_values(array_unique($skills)), 'agents' => array_values(array_unique($agents)), 'created_skills' => $refs];
    }

    public function get(object $admin, string $id): array
    {
        $row = DB::table('ai_skill_management_proposals')->where('id', $id)->where('admin_id', $admin->id)->first();
        abort_unless($row, 404, 'Proposal unavailable.');
        return $this->view($row);
    }

    private function view(object $row): array
    {
        $proposal = $row->proposal ? json_decode($row->proposal, true) : [];
        unset($proposal['actions']);
        return ['id' => $row->id, 'status' => $row->status, 'instruction' => $row->instruction, 'expires_at' => $row->expires_at, 'estimated_cost_usd' => $row->estimated_cost_usd,
            'proposal' => $proposal, 'result' => $row->result ? json_decode($row->result, true) : null];
    }

    public function apply(object $admin, string $id): array
    {
        return DB::transaction(function () use ($admin, $id) {
            $row = DB::table('ai_skill_management_proposals')->where('id', $id)->where('admin_id', $admin->id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Proposal unavailable.');
            if ($row->status === 'applied') return $this->view($row);
            abort_unless($row->status === 'ready' && now('UTC')->lt($row->expires_at), 409, 'This proposal is not ready or has expired. Generate a fresh proposal.');
            $this->checkBaseline(json_decode($row->baseline, true));
            $result = $this->execute($admin, json_decode($row->proposal, true)['actions']);
            DB::table('ai_skill_management_proposals')->where('id', $id)->update(['status' => 'applied', 'result' => json_encode($result), 'updated_at' => now('UTC')]);
            $this->audit($admin, 'ai_apply', $id);
            return $this->get($admin, $id);
        });
    }

    public function discard(object $admin, string $id): array
    {
        return DB::transaction(function () use ($admin, $id) {
            $row = DB::table('ai_skill_management_proposals')->where('id', $id)->where('admin_id', $admin->id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Proposal unavailable.');
            abort_unless(in_array($row->status, ['ready', 'cancelled', 'failed']), 409, 'This proposal cannot be discarded.');
            DB::table('ai_skill_management_proposals')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now('UTC')]);
            $this->audit($admin, 'ai_discard', $id);
            return $this->get($admin, $id);
        });
    }

    private function audit(object $admin, string $action, string $id): void
    {
        DB::table('office_activity_logs')->insert(['admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email,
            'action' => 'AgentSkills@'.$action, 'method' => 'POST', 'path' => 'office/agent-skills/ai/proposals/'.$id, 'details' => json_encode(['proposal_id' => $id]), 'created_at' => now()]);
    }
}
