<?php

namespace App\Ai\Tools;

use App\Ai\Agents\CustomerServiceAgent;
use App\Ai\Skills\KaungKaungService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/** Static public workflow guidance: no identity, database access or writes. */
class CustomerServiceSkills implements Tool
{
    public function __construct(private CustomerServiceAgent $agent) {}

    public function name(): string
    {
        return 'get_customer_service_skills';
    }

    public function description(): string
    {
        return 'Read a Kaung Kaung public MKPOS support workflow, or list skills. This does not read accounts, verify funds, submit tickets, send notifications or change any setting.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['topic' => $schema->string()->enum(['all', ...array_keys($this->agent->skillSession()->guides())])->required()];
    }

    public function handle(Request $request): string
    {
        $input = $request->validate(['topic' => 'required|in:all,'.implode(',', array_keys($this->agent->skillSession()->guides()))]);
        $this->agent->usedTools[] = $this->name();
        $guides = $input['topic'] === 'all' ? $this->agent->skillSession()->guides() : [$input['topic'] => $this->agent->skillSession()->guides()[$input['topic']]];
        foreach ($guides as &$guide) {
            $guide['tools'] = array_values(array_intersect($guide['tools'], array_keys(CustomerServiceKnowledge::TOOLS)));
            if ($input['topic'] === 'all') {
                unset($guide['steps'], $guide['examples']);
            }
        }

        return json_encode(['name' => KaungKaungService::NAME, 'role' => 'MKPOS customer-service and help AI', 'skills' => $guides,
            'notice' => 'Public guidance only. Development/UI/UX text reports use report_application_error; images use the configured image flow. Admin notices are queued by the server to the saved group/topic. Payment approval remains manual.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
