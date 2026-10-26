<?php

namespace App\Mcp\Tools;

use App\Services\McpSupportTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CheckPaymentScreenshot extends Tool
{
    protected string $name = 'check_payment_screenshot';

    protected string $description = 'Business-owner payment-proof pre-validation before subscription submission. Check an owned private evidence upload against the selected plan and recipient account. Returns the extracted transaction ID only when checks pass; submission still requires manual office approval. Requires an owner connection with write scope; spends internal AI credit. The same screenshot and payment selection reuse a recent check.';

    public function schema(JsonSchema $schema): array
    {
        return ['upload_id' => $schema->string()->required(), 'subscription_plan_id' => $schema->integer()->min(1)->required(), 'payment_method_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (array_diff(array_keys($request->all()), ['upload_id', 'subscription_plan_id', 'payment_method_id'])) {
            return Response::error('Unexpected arguments. Owner identity comes from the authenticated connection.');
        }
        $data = $request->validate(['upload_id' => 'required|uuid', 'subscription_plan_id' => 'required|integer|min:1', 'payment_method_id' => 'required|integer|min:1']);
        $context = request()->attributes->get('mcp_support');
        if (! $context || $context['type'] !== 'owner' || ! in_array('write', $context['scopes'], true)) {
            return Response::error('A linked business owner connection with write scope is required.');
        }
        if (! Schema::hasTable('owner_payment_validations')) {
            return Response::error('Payment checking setup is required.');
        }
        try {
            $validation = app(McpSupportTools::class)->validateOwnerProof($context, $data);
            $report = json_decode($validation->report, true);

            return Response::structured(['validation_passed' => true, 'transaction_id' => $report['observed']['transaction_reference'], 'manual_approval_required' => true]);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->first() ?? 'Payment proof needs correction.');
        } catch (HttpExceptionInterface $e) {
            return Response::error($e->getMessage());
        }
    }
}
