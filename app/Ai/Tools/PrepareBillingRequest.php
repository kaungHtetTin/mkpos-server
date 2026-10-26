<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Services\AiBillingRequests;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PrepareBillingRequest implements Tool
{
    public function __construct(private MkposAssistant $agent) {}

    public function name(): string
    {
        return 'prepare_billing_request';
    }

    public function description(): string
    {
        return 'Prepare an owner-only chat Billing form from an owned image in this conversation. Optional image_message_id defaults to latest attached image. Optional subscription_plan_id and payment_method_id must be real active platform options; when both are supplied, run the existing paid receipt validation. Otherwise the owner selects and validates in the form. Never infer a plan from amount alone. Returns the same form on a retry for this message; change selections in its UI. No billing request is submitted, payment approved or subscription activated. The owner must press Submit billing request.';
    }

    public function schema(JsonSchema $s): array
    {
        return ['image_message_id' => $s->integer()->min(1), 'subscription_plan_id' => $s->integer()->min(1), 'payment_method_id' => $s->integer()->min(1), 'message' => $s->string()->max(1000)];
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->name();
        try {
            abort_unless($this->agent->conversationId && $this->agent->messageId, 403, 'An authenticated conversation is required.');
            $result = app(AiBillingRequests::class)->prepare($this->agent->actor, $this->agent->conversationId, $this->agent->messageId, $request->all());
        } catch (ValidationException $e) {
            $result = ['error' => 'billing_form_unavailable', 'fields' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            $result = ['error' => 'billing_form_unavailable', 'status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'billing_form_unavailable', 'message' => 'The billing form could not be prepared. Nothing was submitted.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
