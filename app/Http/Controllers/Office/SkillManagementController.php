<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\AgentSkills\SkillManagement;
use Illuminate\Http\Request;

class SkillManagementController extends Controller
{
    private function admin(Request $request): object
    {
        $admin = $request->user('office');
        abort_unless($admin && $admin->role === 'super_admin', 403, 'Only a super administrator can manage agent skills.');
        return $admin;
    }
    public function prepare(Request $request, SkillManagement $management): array
    {
        $admin = $this->admin($request);
        $data = $request->validate(['request_id' => 'required|uuid', 'instruction' => 'required|string|min:5|max:6000', 'context' => 'present|array:skill_id,agent,editor_draft,editor_slug',
            'context.skill_id' => 'sometimes|integer|min:1|exists:ai_skills,id', 'context.agent' => 'sometimes|in:wyne_wyne,kaung_kaung,sales_ai',
            'context.editor_draft' => 'sometimes|array:title,category,description,body,examples,tools,modules,private,family,topic,screen', 'context.editor_slug' => 'sometimes|nullable|string|max:100']);
        abort_if(strlen(json_encode($data['context'])) > 100000, 422, 'The editor context is too large.');
        if (! app()->runningUnitTests()) {
            @set_time_limit(180);
            ignore_user_abort(true);
        }
        return $management->prepare($admin, $data);
    }
    public function show(Request $request, string $proposal, SkillManagement $management): array { return $management->get($this->admin($request), $proposal); }
    public function apply(Request $request, string $proposal, SkillManagement $management): array
    {
        $admin = $this->admin($request);
        $request->validate(['reviewed' => 'required|accepted']);
        return $management->apply($admin, $proposal);
    }
    public function discard(Request $request, string $proposal, SkillManagement $management): array { return $management->discard($this->admin($request), $proposal); }
}
