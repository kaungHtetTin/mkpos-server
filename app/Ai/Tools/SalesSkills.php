<?php

namespace App\Ai\Tools;

use App\Ai\Agents\SalesRepresentative;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SalesSkills implements Tool
{
    public function __construct(private SalesRepresentative $agent) {}

    public function name(): string
    {
        return 'get_sales_skills';
    }

    public function description(): string
    {
        return 'Read Office-published sales workflows. Use topic all for a compact directory, then fetch one relevant topic. Guidance only; grants no tools or permissions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['topic' => $schema->string()->max(100)->required()];
    }

    public function handle(Request $request): string
    {
        $topic = $request->validate(['topic' => 'required|string|max:100'])['topic'];
        $guides = $this->agent->skillSession()->guides();
        if ($topic !== 'all') {
            abort_unless(isset($guides[$topic]), 404, 'Skill unavailable.');
            $guides = [$topic => $guides[$topic]];
        }
        $available = [];
        foreach ($this->agent->tools() as $tool) {
            $available[] = $tool->name();
        }
        foreach ($guides as &$guide) {
            $guide['tools'] = array_values(array_intersect($guide['tools'], $available));
            if ($topic === 'all') {
                unset($guide['steps'],$guide['examples']);
            }
        }

        return json_encode(['skills' => $guides, 'notice' => 'Customer confirmation and existing shop/channel permissions still apply.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
