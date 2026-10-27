<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Skills\ErrorSupport;
use App\Services\ErrorReports;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErrorSupportTool implements Tool
{
    public const NAMES = ['get_error_support_guide', 'submit_error_report', 'get_my_error_reports'];

    public function __construct(private MkposAssistant $agent, private string $toolName) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return match ($this->toolName) {
            'get_error_support_guide' => 'Read prompt app-defect reporting and safe support guidance, plus IDs/names of recent screenshot messages in this owned conversation. No writes.',
            'submit_error_report' => 'Promptly prepare an MKPOS development/implementation error or UI/UX defect report without requiring troubleshooting first and request the owner contact form before submission. Does NOT submit to Office until the owner completes the form. Server binds owner/business/app/current user message. Include actual symptom, expected result and steps attempted; current screenshot attaches automatically, or choose up to 3 relevant screenshot_message_ids. Idempotent per user message. No business records are changed. Let the owner choose Facebook, Telegram, Viber or phone in the form; do not ask a separate contact question first. Contact is optional; only the owner form can provide or skip it. Never invent contacts or claim submission while awaiting_owner_contact.',
            default => 'Read this owner own error report references, status and public resolution. Optional report_id or bounded pagination. Never returns internal Office notes.',
        };
    }

    public function schema(JsonSchema $s): array
    {
        return match ($this->toolName) {
            'get_error_support_guide' => [],
            'submit_error_report' => ['title' => $s->string()->max(160)->required(), 'description' => $s->string()->max(4000)->required(),
                'expected_result' => $s->string()->max(2000), 'attempted_steps' => $s->string()->max(3000),
                'screenshot_message_ids' => $s->array()->max(3)->items($s->integer()->min(1))],
            default => ['report_id' => $s->integer()->min(1), 'offset' => $s->integer()->min(0)->max(10000), 'limit' => $s->integer()->min(1)->max(20)],
        };
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->toolName;
        try {
            $service = app(ErrorReports::class);
            $result = match ($this->toolName) {
                'get_error_support_guide' => ErrorSupport::guide() + $service->context($this->agent->actor, $this->agent->conversationId, $this->agent->messageId),
                'submit_error_report' => $service->prepare($this->agent->actor, $this->agent->conversationId ?? '', $this->agent->messageId ?? 0, $this->agent->app, $request->all()),
                default => $service->mine($this->agent->actor, $request->all()),
            };
        } catch (ValidationException $e) {
            $result = ['error' => 'invalid_report', 'fields' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            $result = ['error' => 'error_report_unavailable', 'message' => $e->getMessage(), 'status' => $e->getStatusCode()];
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'error_report_unavailable', 'message' => 'The report could not be submitted. Do not claim it was sent.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
