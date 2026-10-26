<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessReadTool;
use App\Http\Controllers\Api\ReportController;
use App\Models\User;
use App\Services\AiBusinessReader;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiBusinessToolsTest extends TestCase
{
    use AiBusinessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function read(string $tool, array $args = []): array
    {
        return app(AiBusinessReader::class)->run($this->owner, $tool, $args);
    }

    private function invoke(string $tool, array $args = [], ?User $actor = null): array
    {
        return json_decode((new BusinessReadTool(new MkposAssistant($actor ?? $this->owner), $tool))->handle(new ToolRequest($args)), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_all_twelve_capabilities_are_owner_only_tenant_scoped_and_read_only(): void
    {
        $this->assertCount(21, BusinessReadTool::DESCRIPTIONS);
        $this->assertCount(27, (new MkposAssistant($this->owner))->tools());
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            $reads[] = $query->sql;
        });
        foreach (array_keys(BusinessReadTool::DESCRIPTIONS) as $tool) {
            $result = $this->read($tool, $tool === 'get_product_details' ? ['product_id' => 1] : []);
            $this->assertSame('MMK', $result['currency']);
            $this->assertStringNotContainsString('Private Other Business', json_encode($result));
            $this->assertStringNotContainsString('private-tracking-secret', json_encode($result));
            if (isset($result['data']['total']) && ! in_array($tool, ['get_setup_progress', 'get_next_setup_step'])) {
                $this->assertSame(1, $result['data']['total'], $tool);
            }
        }
        foreach ($reads as $sql) {
            $this->assertMatchesRegularExpression('/^select /i', $sql);
        }
        $staff = clone $this->owner;
        $staff->role = 'staff';
        $this->assertSame(['MkposKnowledge', 'get_wyne_wyne_skills', 'open_app_screen'], collect((new MkposAssistant($staff))->tools())->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool))->all());
        $this->assertSame('business_access_denied', $this->invoke('search_sales', [], $staff)['error']);
    }

    public function test_cross_business_ids_and_identity_injection_do_not_reveal_records(): void
    {
        foreach (['get_product_details' => 'product_id', 'get_customer_account' => 'customer_id', 'get_supplier_account' => 'supplier_id'] as $tool => $key) {
            $this->assertSame('record_unavailable', $this->invoke($tool, [$key => 2])['error']);
        }
        foreach (['search_sales' => 'sale_id', 'search_purchases' => 'purchase_id', 'get_shop_orders' => 'order_id'] as $tool => $key) {
            $this->assertSame([], $this->read($tool, [$key => 2])['data']['items']);
            $this->assertArrayNotHasKey('line_items', $this->read($tool, [$key => 2])['data']);
        }
        $this->assertSame('invalid_arguments', $this->invoke('search_sales', ['business_id' => 2])['error']);
        $this->assertSame('invalid_arguments', $this->invoke('get_business_context', ['sql' => 'SELECT * FROM users'])['error']);
        app(TenantContext::class)->set(2);
        $this->assertSame('business_access_denied', $this->invoke('get_business_context')['error']);
    }

    public function test_stale_owner_inactive_business_and_disabled_modules_fail_closed(): void
    {
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $this->assertSame('business_access_denied', $this->invoke('search_products')['error']);
        DB::table('users')->where('id', 1)->update(['role' => 'owner', 'is_active' => false]);
        $this->assertSame('business_access_denied', $this->invoke('search_products')['error']);
        DB::table('users')->where('id', 1)->update(['is_active' => true]);
        DB::table('businesses')->where('id', 1)->update(['status' => 'inactive']);
        $this->assertSame('business_access_denied', $this->invoke('search_products')['error']);
        DB::table('businesses')->where('id', 1)->update(['status' => 'active']);
        config(['mkpos.enabled_modules' => ['products']]);
        $this->assertSame('business_access_denied', $this->invoke('get_business_report')['error']);
        $this->assertNotContains('get_business_report', array_map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : '', (new MkposAssistant($this->owner))->tools()));
        $this->assertSame(1, $this->read('search_products')['data']['total']);
    }

    public function test_local_midnight_boundaries_completed_status_and_report_screen_parity(): void
    {
        $this->assertSame('2026-10-25', $this->read('get_business_context')['data']['local_date']);
        foreach (['2026-10-24 17:29:59', '2026-10-24 17:30:00', '2026-10-25 17:29:59', '2026-10-25 17:30:00'] as $i => $at) {
            DB::table('sales')->insert(['business_id' => 1, 'receipt_no' => 'BOUNDARY-'.$i, 'payment_type' => 'cash', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10, 'created_at' => $at]);
        }
        DB::table('sales')->insert(['business_id' => 1, 'receipt_no' => 'VOID', 'payment_type' => 'cash', 'subtotal' => 999, 'total' => 999, 'status' => 'voided', 'created_at' => '2026-10-24 18:00:00']);
        $this->assertSame(120, $this->read('search_sales')['data']['summary']['total']);
        $ai = $this->read('get_business_report')['data']['report'];
        $screen = app(ReportController::class)->summary(Request::create('/', 'GET', ['start' => '2026-10-25', 'end' => '2026-10-25']));
        unset($screen['sales_trends']);
        $this->assertSame($screen, $ai);
        $this->assertSame(120, $ai['sales_total']);
        $this->assertSame(70, $ai['profit_estimate']);
        $this->assertSame(50, $ai['current_accounts']['receivable_total']);
        $this->assertSame(125, $ai['current_accounts']['supplier_payable_total']);
        $previous = $this->read('get_business_report', ['start' => '2026-10-24', 'end' => '2026-10-24']);
        $this->assertSame(10, $previous['data']['report']['sales_total']);
        $this->assertSame(50, $previous['data']['report']['current_accounts']['receivable_total']);
        $this->assertSame('invalid_arguments', $this->invoke('search_sales', ['start' => '2026-10-26', 'end' => '2026-10-25'])['error']);
        $this->assertSame('invalid_arguments', $this->invoke('search_sales', ['start' => 'yesterday'])['error']);
    }

    public function test_current_balances_respect_payment_directions_and_ignore_voided_payments(): void
    {
        DB::table('customer_payments')->insert(['business_id' => 1, 'customer_id' => 1, 'direction' => 'shop_to_customer', 'amount' => 5]);
        DB::table('customer_payments')->insert(['business_id' => 1, 'customer_id' => 1, 'amount' => 500, 'status' => 'voided']);
        DB::table('supplier_payments')->insert(['business_id' => 1, 'supplier_id' => 1, 'direction' => 'supplier_to_shop', 'amount' => 10]);
        $customer = $this->read('get_customer_account', ['customer_id' => 1])['data'];
        $supplier = $this->read('get_supplier_account', ['supplier_id' => 1])['data'];
        $this->assertEquals(55, $customer['items'][0]['balance']);
        $this->assertEquals(135, $supplier['items'][0]['balance']);
        $this->assertSame(1, $customer['transactions']['total']);
        $this->assertSame(1, $supplier['transactions']['total']);
        // Even inconsistent foreign-tenant children cannot alter this business's balances.
        DB::table('customer_payments')->insert(['business_id' => 2, 'customer_id' => 1, 'amount' => 700]);
        $this->assertEquals(55, $this->read('get_customer_account', ['sort' => 'balance_desc'])['data']['items'][0]['balance']);
    }

    public function test_pagination_totals_filters_details_and_text_bounds(): void
    {
        for ($i = 3; $i <= 27; $i++) {
            DB::table('products')->insert(['id' => $i, 'business_id' => 1, 'name' => 'Soap '.$i]);
        }
        $first = $this->read('search_products', ['limit' => 20])['data'];
        $second = $this->read('search_products', ['limit' => 20, 'offset' => $first['next_offset']])['data'];
        $this->assertSame(26, $first['total']);
        $this->assertCount(20, $first['items']);
        $this->assertCount(6, $second['items']);
        $this->assertFalse($second['has_more']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $this->assertSame('invalid_arguments', $this->invoke('search_products', ['limit' => 1000])['error']);
        $this->assertSame('invalid_arguments', $this->invoke('search_sales', ['offset' => -1])['error']);
        $this->assertSame(0, $this->read('search_products', ['query' => 'no match'])['data']['total']);
        $this->assertSame(1, $this->read('get_inventory_report', ['mode' => 'movements', 'product_id' => 1])['data']['total']);
        $this->assertSame('Retail', $this->read('get_product_details', ['product_id' => 1])['data']['prices']['items'][0]['name']);
        $this->assertSame(1, $this->read('search_sales', ['sale_id' => 1])['data']['line_items']['total']);
        $this->assertSame(1, $this->read('search_purchases', ['purchase_id' => 1])['data']['line_items']['total']);
        $order = $this->read('get_shop_orders', ['order_id' => 1, 'status' => 'packing'])['data'];
        $this->assertSame(1, $order['line_items']['total']);
        $this->assertStringNotContainsString('private-key', json_encode($order));
        $this->assertSame(10, $this->read('get_expense_report', ['category' => 'Rent'])['data']['total_amount']);
        DB::table('customers')->where('id', 1)->update(['name' => str_repeat('x', 2000)]);
        $this->assertLessThan(550, mb_strlen($this->read('get_customer_account')['data']['items'][0]['name']));
    }

    public function test_sdk_exposes_unique_schemas_and_executes_context_then_business_lookup_without_network(): void
    {
        config(['ai.providers.deepseek.key' => 'fake-key']);
        $calls = 0;
        Http::fake(['api.deepseek.com/*' => function ($request) use (&$calls) {
            $calls++;
            $names = array_column(array_column($request['tools'], 'function'), 'name');
            $this->assertCount(27, array_unique($names));
            $this->assertContains('get_wyne_wyne_skills', $names);
            $this->assertContains('get_business_context', $names);
            $this->assertContains('search_sales', $names);
            $this->assertSame($calls === 1 ? ['type' => 'function', 'function' => ['name' => 'get_business_context']] : 'auto', $request['tool_choice']);
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'];
            $this->assertStringContainsString('Current server-authenticated session: business owner', $system);
            $this->assertStringContainsString('Earlier assistant replies claiming no access', $system);
            if ($calls === 3) {
                $results = collect($request['messages'])->where('role', 'tool')->map(fn ($m) => json_decode($m['content'], true))->values();
                $this->assertSame('2026-10-25', $results[0]['data']['local_date']);
                $this->assertSame(100, $results[1]['data']['summary']['total']);
                $this->assertSame(1, $results[1]['data']['total']);
                $this->assertStringNotContainsString('Private Other Business', json_encode($results));
                $message = ['role' => 'assistant', 'content' => 'Sales on 2026-10-25: MMK 100 from one receipt.'];
            } else {
                $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'business-'.$calls, 'type' => 'function', 'function' => [
                    'name' => $calls === 1 ? 'get_business_context' : 'search_sales',
                    'arguments' => $calls === 1 ? '{}' : json_encode(['start' => '2026-10-25', 'end' => '2026-10-25']),
                ]]]];
            }

            return Http::response(['id' => 'business-response-'.$calls, 'object' => 'chat.completion', 'model' => 'deepseek-flash',
                'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $calls === 3 ? 'stop' : 'tool_calls']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]]);
        }]);
        $agent = new MkposAssistant($this->owner, [
            new Message('user', 'Summarize my business today'),
            new Message('assistant', 'I cannot access your sales, expense, inventory or account data. I cannot confirm owner authorization from this chat.'),
        ]);
        $result = $agent->prompt('How much did we sell today?', provider: 'deepseek', model: 'deepseek-flash');
        $this->assertSame(3, $calls);
        $this->assertStringContainsString('MMK 100', $result->text);
        $this->assertSame(['get_business_context', 'search_sales'], $agent->usedTools);
    }

    public function test_selected_invoice_line_items_can_be_paginated_without_losing_the_parent(): void
    {
        for ($i = 0; $i < 24; $i++) {
            DB::table('sale_items')->insert(['business_id' => 1, 'sale_id' => 1, 'product_id' => 1, 'product_name' => 'Soap', 'quantity' => 1, 'unit_price' => 100, 'unit_cost' => 40, 'line_total' => 100]);
        }
        $page = $this->read('search_sales', ['sale_id' => 1, 'limit' => 20, 'offset' => 20])['data'];
        $this->assertSame(1, $page['items'][0]['id']);
        $this->assertCount(5, $page['line_items']['items']);
        $this->assertFalse($page['line_items']['has_more']);
    }

    public function test_full_history_search_and_separate_period_comparison(): void
    {
        DB::table('sales')->where('id', 1)->update(['created_at' => '2025-01-01 00:00:00']);
        $this->assertSame(0, $this->read('search_sales')['data']['summary']['count']);
        $history = $this->read('search_sales', ['all_time' => true, 'sale_id' => 1]);
        $this->assertSame(1, $history['data']['summary']['count']);
        $this->assertTrue($history['date_range']['all_time']);
        $this->assertNull($history['date_range']['start']);
        $this->assertSame(100, $this->read('get_business_report', ['all_time' => true])['data']['report']['sales_total']);
        $this->assertSame(0, $this->read('get_business_report')['data']['report']['sales_total']);
        $this->assertSame('invalid_arguments', $this->invoke('search_sales', ['all_time' => true, 'start' => '2026-10-25'])['error']);
        $this->assertSame(10, $this->read('get_expense_report', ['all_time' => true])['data']['total_amount']);
    }

    public function test_report_does_not_net_customer_advances_against_other_customer_debts(): void
    {
        DB::table('customer_payments')->insert(['business_id' => 1, 'customer_id' => 1, 'amount' => 100]);
        DB::table('customers')->insert(['id' => 3, 'business_id' => 1, 'name' => 'Another customer']);
        DB::table('sales')->insert(['business_id' => 1, 'customer_id' => 3, 'receipt_no' => 'CREDIT-3', 'payment_type' => 'credit', 'subtotal' => 150, 'total' => 150, 'credit_amount' => 150, 'created_at' => '2026-10-24 18:00:00']);
        DB::table('supplier_payments')->insert(['business_id' => 1, 'supplier_id' => 1, 'amount' => 200]);
        $report = $this->read('get_business_report')['data']['report'];
        $this->assertSame(150, $report['current_accounts']['receivable_total']);
        $this->assertSame(50, $report['current_accounts']['customer_payable_total']);
        $this->assertSame(0, $report['current_accounts']['supplier_payable_total']);
        $this->assertSame(75, $report['current_accounts']['supplier_credit_total']);
        $this->assertSame(3, $this->read('get_customer_account', ['sort' => 'balance_desc'])['data']['items'][0]['id']);
    }
}
