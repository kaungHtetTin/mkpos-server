<?php

namespace App\Ai\Tools;

use App\Ai\Agents\CustomerServiceAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CustomerServiceErrorReport implements Tool
{
    public function __construct(private CustomerServiceAgent $agent) {}

    public function name(): string
    {
        return 'report_application_error';
    }

    public function description(): string
    {
        return 'Prepare a report of an MKPOS UI/UX defect or development/implementation error for the configured admin Telegram group. Use promptly without troubleshooting first. Supply only observed symptoms, affected app/page, expected behavior and steps already known; never invent a cause, attempted fix or include secrets. No recipient arguments or business changes. The server checks and queues the report after this turn; do not claim delivery.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['summary' => $schema->string()->max(1200)->required()];
    }

    public function handle(Request $request): string
    {
        $data = $request->validate(['summary' => 'required|string|max:1200']);
        $this->agent->usedTools[] = $this->name();
        $this->agent->errorReport = ['kind' => 'error_unknown', 'summary' => $data['summary']];

        return json_encode(['status' => 'prepared', 'message' => 'The server will check the configured admin group and queue this report before publishing the reply. Delivery is not confirmed.']);
    }
}
