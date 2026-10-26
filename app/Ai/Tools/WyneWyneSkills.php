<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Skills\WyneWyneService;
use App\Services\AiAssistantAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class WyneWyneSkills implements Tool
{
    public function __construct(private MkposAssistant $agent) {}

    public function name(): string
    {
        return 'get_wyne_wyne_skills';
    }

    public function description(): string
    {
        return 'Read the relevant Wyne Wyne workflow or list skills available to the current session. Guides only; no business data, writes or additional permissions. Tools listed are candidates and may still be denied by current module/subscription checks.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['topic' => $schema->string()->enum(['all', ...array_keys(WyneWyneService::GUIDES)])->required()];
    }

    public function handle(Request $request): string
    {
        $input = $request->validate(['topic' => 'required|in:all,'.implode(',', array_keys(WyneWyneService::GUIDES))]);
        $this->agent->usedTools[] = $this->name();
        $access = app(AiAssistantAccess::class)->context($this->agent->actor);
        $guides = array_filter(WyneWyneService::GUIDES, function ($guide) use ($access) {
            return (! $guide['private'] || $access['can_read_business_data'])
                && (! $guide['modules'] || count(array_intersect($guide['modules'], $access['enabled_modules'])) > 0);
        });
        if ($input['topic'] !== 'all' && ! isset($guides[$input['topic']])) {
            return json_encode(['error' => 'skill_unavailable', 'message' => 'This workflow is unavailable with the current session or enabled modules. Public guidance remains available.']);
        }
        if ($input['topic'] !== 'all') {
            $guides = [$input['topic'] => $guides[$input['topic']]];
        }
        $tools = [];
        foreach ($this->agent->tools() as $tool) {
            $tools[] = method_exists($tool, 'name') ? $tool->name() : class_basename($tool);
        }
        if (! $access['can_read_business_data']) {
            $tools = array_values(array_intersect($tools, ['open_app_screen', 'MkposKnowledge', $this->name()]));
        }
        foreach ($guides as &$guide) {
            $guide['available_tools'] = array_values(array_intersect($guide['tools'], $tools));
            unset($guide['private'], $guide['modules'], $guide['tools']);
            if ($input['topic'] === 'all') {
                unset($guide['steps']);
            }
        }

        return json_encode(['skills' => $guides, 'notice' => 'Guidance only. Current server access, module and subscription checks apply to every tool call. Writes require the native owner review/confirmation.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
