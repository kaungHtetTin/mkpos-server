<?php

namespace App\Ai\Tools;

use App\Ai\Agents\MkposAssistant;
use App\Services\AiBusinessAdvisor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BusinessAdvisorTool implements Tool
{
    public function __construct(private MkposAssistant $agent, private string $tool) {}

    public function name(): string
    {
        return $this->tool;
    }

    public function description(): string
    {
        return AiBusinessAdvisor::TOOLS[$this->tool];
    }

    public function schema(JsonSchema $s): array
    {
        $page = ['limit' => $s->integer()->min(1)->max(20), 'offset' => $s->integer()->min(0)->max(1000000)];

        return match ($this->tool) {
            'check_business_data_integrity' => ['area' => $s->string()->enum(['all', 'products', 'sales', 'purchases', 'accounts', 'settings']), 'scan_limit' => $s->integer()->min(1)->max(500), 'offset' => $s->integer()->min(0)->max(1000000)],
            'explain_business_issue' => ['issue_id' => $s->string()->max(120)->required()],
            'prepare_issue_fix' => ['issue_id' => $s->string()->max(120)->required(), 'values' => $s->object(['base_unit' => $s->string()->max(50), 'purchase_unit' => $s->string()->max(50), 'purchase_conversion_factor' => $s->number()->min(1)->max(1000000), 'sku' => $s->string()->max(255), 'barcode' => $s->string()->max(255), 'low_stock_threshold' => $s->number()->min(0)])->required()],
            'get_restock_suggestions' => ['lookback_days' => $s->integer()->min(1)->max(365), 'coverage_days' => $s->integer()->min(1)->max(365), 'lead_days' => $s->integer()->min(0)->max(365), ...$page],
            'get_slow_moving_products' => ['lookback_days' => $s->integer()->min(1)->max(3650), 'max_sold_quantity' => $s->number()->min(0)->max(1000000), ...$page],
            'get_outstanding_accounts' => ['party' => $s->string()->enum(['all', 'customer', 'supplier']), ...$page],
            'check_daily_closing' => ['date' => $s->string()->description('Business-local YYYY-MM-DD.'), 'cash_method' => $s->string()->max(50), 'opening_cash' => $s->integer()->min(0)->max(1000000000000), 'counted_cash' => $s->integer()->min(0)->max(1000000000000)],
            'prepare_purchase_from_invoice' => ['image_message_id' => $s->integer()->min(1)->required(), 'supplier_id' => $s->integer()->min(1), 'supplier_name' => $s->string()->max(255), 'invoice_reference' => $s->string()->max(120), 'invoice_total' => $s->integer()->min(0)->max(1000000000000)->required(), 'currency' => $s->string()->max(10)->required(), 'items' => $s->array()->min(1)->max(50)->items($s->object(['product_id' => $s->integer()->min(1)->required(), 'source_name' => $s->string()->max(255)->required(), 'unit_name' => $s->string()->max(50)->required(), 'quantity' => $s->number()->min(.001)->max(1000000)->required(), 'foc_quantity' => $s->number()->min(0)->max(1000000), 'unit_cost' => $s->integer()->min(0)->max(1000000000)->required()]))->required()],
        };
    }

    public function handle(Request $request): string
    {
        $this->agent->usedTools[] = $this->tool;
        try {
            $result = app(AiBusinessAdvisor::class)->run($this->agent->actor, $this->tool, $request->all(), $this->agent->conversationId, $this->agent->messageId);
        } catch (ValidationException $e) {
            $result = ['error' => 'invalid_arguments', 'fields' => $e->errors()];
        } catch (HttpExceptionInterface $e) {
            $result = ['error' => 'business_advisor_unavailable', 'status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);
            $result = ['error' => 'business_advisor_unavailable', 'message' => 'The requested check or draft could not complete. No business change was saved.'];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
