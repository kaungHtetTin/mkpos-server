<?php

namespace App\Ai\Tools;

use App\Services\AgentSkills\ManagementContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SkillManagementRead implements Tool
{
    public function __construct(private ManagementContext $context) {}
    public function name(): string { return 'read_skill_management'; }
    public function description(): string { return 'Read only the Office skill catalog, a skill, its version, or agent assignments. No business data, settings, credentials, writes or publishing.'; }
    public function schema(JsonSchema $schema): array
    {
        return ['kind' => $schema->string()->enum(['catalog', 'skill', 'version', 'assignments'])->required(),
            'skill_id' => $schema->integer()->min(1), 'version_id' => $schema->integer()->min(1),
            'agent' => $schema->string()->enum(['wyne_wyne', 'kaung_kaung', 'sales_ai']),
            'search' => $schema->string()->max(200), 'offset' => $schema->integer()->min(0)];
    }
    public function handle(Request $request): string
    {
        $data = $request->validate(['kind' => 'required|in:catalog,skill,version,assignments', 'skill_id' => 'required_if:kind,skill,version|integer|min:1',
            'version_id' => 'required_if:kind,version|integer|min:1', 'agent' => 'required_if:kind,assignments|in:wyne_wyne,kaung_kaung,sales_ai', 'search' => 'sometimes|string|max:200', 'offset' => 'sometimes|integer|min:0']);
        $result = match ($data['kind']) {
            'catalog' => $this->context->catalog($data['search'] ?? '', $data['offset'] ?? 0),
            'skill' => $this->context->skill($data['skill_id']),
            'version' => $this->context->version($data['skill_id'], $data['version_id']),
            'assignments' => $this->context->assignments($data['agent']),
        };
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
