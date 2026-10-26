<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessAdvisorTool;
use App\Services\AiBusinessActions;
use App\Services\AiBusinessAdvisor;
use App\Services\AiChatImages;
use App\Services\AiInvoicePurchases;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiBusinessAdvisorTest extends TestCase
{
    use AiBusinessFixture;

    private string $conversation;

    private int $message;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
        Storage::fake('local');
        foreach (['2026_10_25_000001_create_ai_assistant_tables.php', '2026_10_25_000004_create_ai_business_actions.php', '2026_10_25_000011_create_ai_purchase_drafts.php'] as $m) {
            (require database_path('migrations/'.$m))->up();
        }
        DB::table('ai_assistant_settings')->update(['enabled' => true]);
        $this->mock(SubscriptionService::class, fn ($m) => $m->shouldReceive('status')->andReturn(['can_mutate' => true]));
        $this->conversation = (string) Str::uuid();
        DB::table('ai_conversations')->insert(['id' => $this->conversation, 'business_id' => 1, 'user_id' => 1, 'title' => 'Advice']);
        $file = UploadedFile::fake()->image('invoice.png', 100, 150);
        $meta = app(AiChatImages::class)->inspect($file);
        $meta['_image_path'] = $file->store('ai-chat-images/1/'.$this->conversation, 'local');
        $this->message = DB::table('ai_messages')->insertGetId(['conversation_id' => $this->conversation, 'role' => 'user', 'body' => 'Review this invoice', 'attachment' => json_encode($meta), 'status' => 'completed']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function runTool(string $tool, array $a = []): array
    {
        return app(AiBusinessAdvisor::class)->run($this->owner, $tool, $a, $this->conversation, $this->message)['data'];
    }

    private function invoice(array $replace = []): array
    {
        return array_replace(['image_message_id' => $this->message, 'supplier_id' => 1, 'invoice_reference' => 'INV-1', 'invoice_total' => 300, 'currency' => 'MMK', 'items' => [['product_id' => 1, 'source_name' => 'Soap invoice text', 'unit_name' => 'Unit', 'quantity' => 3, 'unit_cost' => 100]]], $replace);
    }

    private function confirm(array $a): array
    {
        return app(AiBusinessActions::class)->confirm($this->owner, $this->conversation, $a['id'], $a['preview_hash'], $a['preview']['total']);
    }

    public function test_all_eight_tools_register_only_for_owners_and_recheck_roles_and_modules(): void
    {
        $agent = new MkposAssistant($this->owner);
        $this->assertCount(8, collect($agent->tools())->filter(fn ($t) => $t instanceof BusinessAdvisorTool));
        $staff = clone $this->owner;
        $staff->role = 'staff';
        $this->assertCount(0, collect((new MkposAssistant($staff))->tools())->filter(fn ($t) => $t instanceof BusinessAdvisorTool));
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        try {
            $this->runTool('get_restock_suggestions');
            $this->fail('Stale owner allowed');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        DB::table('users')->where('id', 1)->update(['role' => 'owner']);
        config(['mkpos.enabled_modules' => ['products']]);
        try {
            $this->runTool('get_restock_suggestions');
            $this->fail('Missing module allowed');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_integrity_is_bounded_scoped_and_explanations_use_current_evidence(): void
    {
        DB::table('products')->where('id', 1)->update(['purchase_conversion_factor' => 0, 'stock' => -2]);
        $result = $this->runTool('check_business_data_integrity', ['area' => 'products']);
        $this->assertCount(2, $result['findings']);
        $this->assertStringNotContainsString('Private Other Business', json_encode($result));
        $id = 'products:1:invalid_conversion';
        $this->assertSame('detected', $this->runTool('explain_business_issue', ['issue_id' => $id])['status']);
        DB::table('products')->where('id', 1)->update(['purchase_conversion_factor' => 1]);
        $this->assertSame('resolved_or_unavailable', $this->runTool('explain_business_issue', ['issue_id' => $id])['status']);
        $this->assertNull($this->runTool('explain_business_issue', ['issue_id' => 'products:2:negative_stock'])['finding']);
        DB::table('products')->insert(['business_id' => 1, 'name' => 'Extra', 'stock' => 1]);
        $r = $this->runTool('check_business_data_integrity', ['area' => 'products', 'scan_limit' => 1]);
        $this->assertFalse($r['complete']);
        $this->assertSame(1, $r['coverage']['products']['next_offset']);
    }

    public function test_integrity_finds_header_and_account_links_without_changing_history(): void
    {
        DB::table('sales')->where('id', 1)->update(['total' => 999]);
        $r = $this->runTool('check_business_data_integrity', ['area' => 'sales']);
        $this->assertSame('total_mismatch', $r['findings'][0]['code']);
        DB::table('customer_payments')->where('business_id', 1)->update(['customer_id' => 2]);
        $r = $this->runTool('check_business_data_integrity', ['area' => 'accounts']);
        $this->assertSame('missing_customer', $r['findings'][0]['code']);
        $this->assertSame(999, DB::table('sales')->where('id', 1)->value('total'));
        $this->expectException(HttpExceptionInterface::class);
        $this->runTool('prepare_issue_fix', ['issue_id' => 'sales:1:total_mismatch', 'values' => ['total' => 100]]);
    }

    public function test_issue_fix_is_a_reviewed_proposal_and_never_changes_stock(): void
    {
        DB::table('products')->where('id', 1)->update(['purchase_conversion_factor' => 0]);
        $action = $this->runTool('prepare_issue_fix', ['issue_id' => 'products:1:invalid_conversion', 'values' => ['purchase_conversion_factor' => 1]])['action'];
        $this->assertEquals(0, DB::table('products')->where('id', 1)->value('purchase_conversion_factor'));
        $this->confirm($action);
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('purchase_conversion_factor'));
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
    }

    public function test_restock_uses_base_units_foc_reservations_and_excludes_voided_or_foreign_sales(): void
    {
        DB::table('sale_items')->where('business_id', 1)->update(['quantity' => 10, 'foc_quantity' => 5]);
        DB::table('ecommerce_stock_reservations')->insert(['business_id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 1, 'status' => 'active']);
        $r = $this->runTool('get_restock_suggestions', ['lookback_days' => 30, 'coverage_days' => 14]);
        $p = $r['items'][0];
        $this->assertSame(15.0, $p['issued_including_foc']);
        $this->assertSame(1.0, $p['available_stock']);
        $this->assertSame(6.0, $p['suggested_quantity']);
        $this->assertSame(1, $r['total']);
        DB::table('sales')->where('id', 1)->update(['status' => 'voided']);
        $p = $this->runTool('get_restock_suggestions')['items'][0];
        $this->assertSame(0.0, $p['issued_including_foc']);
        $this->assertSame(2.0, $p['suggested_quantity']);
    }

    public function test_slow_movers_show_stock_value_last_sale_and_paginate(): void
    {
        DB::table('products')->insert(['business_id' => 1, 'name' => 'No sales', 'stock' => 10, 'cost' => 7]);
        $r = $this->runTool('get_slow_moving_products');
        $this->assertSame(1, $r['total']);
        $this->assertSame(70.0, $r['items'][0]['stock_value_at_current_cost']);
        $this->assertNull($r['items'][0]['last_sale_at']);
        $r = $this->runTool('get_slow_moving_products', ['max_sold_quantity' => 2, 'limit' => 1]);
        $this->assertTrue($r['has_more']);
        $this->assertNotNull($r['items'][0]['last_sale_at']);
    }

    public function test_outstanding_balances_net_payments_and_keep_directions_separate(): void
    {
        $r = $this->runTool('get_outstanding_accounts');
        $this->assertSame(2, $r['total']);
        $this->assertSame(125.0, $r['items'][0]['amount']);
        $this->assertSame('business_owes_supplier', $r['items'][0]['direction']);
        $this->assertSame(50.0, $r['items'][1]['amount']);
        $this->assertNull($r['items'][0]['overdue']);
        DB::table('customer_payments')->where('business_id', 1)->update(['amount' => 100]);
        $r = $this->runTool('get_outstanding_accounts', ['party' => 'customer']);
        $this->assertSame('business_owes_customer', $r['items'][0]['direction']);
        $this->assertSame(20.0, $r['items'][0]['amount']);
    }

    public function test_closing_requires_counted_cash_and_separates_payment_channels_and_local_dates(): void
    {
        $r = $this->runTool('check_daily_closing');
        $this->assertNull($r['difference']);
        $this->assertSame(-35.0, $r['recorded_cash_movement']);
        $r = $this->runTool('check_daily_closing', ['opening_cash' => 100, 'counted_cash' => 65]);
        $this->assertSame('matches', $r['status']);
        $this->assertSame(0.0, $r['difference']);
        DB::table('sales')->where('id', 1)->update(['payment_method' => 'KPay']);
        $r = $this->runTool('check_daily_closing', ['opening_cash' => 100, 'counted_cash' => 65]);
        $this->assertSame(20.0, $r['difference']);
        $this->assertCount(2, $r['methods']);
        config(['mkpos.enabled_modules' => ['reports', 'sales']]);
        $this->assertNull($this->runTool('check_daily_closing', ['opening_cash' => 100, 'counted_cash' => 65])['expected_cash']);
    }

    public function test_invalid_and_untrusted_arguments_never_gain_authority(): void
    {
        foreach ([['get_restock_suggestions', ['business_id' => 2]], ['prepare_issue_fix', ['issue_id' => 'products:1:negative_stock', 'values' => ['stock' => 100]]], ['check_daily_closing', ['cash_method' => 'Cahs']]] as [$tool,$args]) {
            try {
                $this->runTool($tool, $args);
                $this->fail('Invalid arguments accepted');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->expectException(ValidationException::class);
        $this->runTool('check_daily_closing', ['counted_cash' => 100]);
    }

    public function test_invoice_draft_requires_review_and_replays_without_posting_purchase_stock_or_payment(): void
    {
        $r = $this->runTool('prepare_purchase_from_invoice', $this->invoice());
        $a = $r['action'];
        $this->assertDatabaseCount('ai_purchase_drafts', 0);
        $this->assertSame('pending', $a['status']);
        $saved = $this->confirm($a);
        $id = $saved['result']['purchase_draft_id'];
        $this->assertSame($id, $this->confirm($a)['result']['purchase_draft_id']);
        $this->assertDatabaseCount('ai_purchase_drafts', 1);
        $this->assertDatabaseCount('purchases', 2);
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $draft = app(AiInvoicePurchases::class)->show($this->owner, $id);
        $this->assertSame(0, $draft['form']['paid_amount']);
        $this->assertFalse($draft['posted']);
        $this->assertSame('Soap', $draft['form']['items'][0]['product']['name']);
        Http::assertNothingSent();
    }

    public function test_invoice_invalid_currency_units_total_and_foreign_product_are_rejected(): void
    {
        foreach ([['currency' => 'USD'], ['invoice_total' => 999], ['items' => [['product_id' => 2, 'source_name' => 'Other', 'unit_name' => 'Unit', 'quantity' => 3, 'unit_cost' => 100]]], ['items' => [['product_id' => 1, 'source_name' => 'Soap', 'unit_name' => 'Unknown', 'quantity' => 3, 'unit_cost' => 100]]]] as $change) {
            try {
                $this->runTool('prepare_purchase_from_invoice', $this->invoice($change));
                $this->fail('Invalid invoice allowed');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('ai_business_actions', 0);
    }

    public function test_invoice_snapshot_changes_block_confirmation_and_drafts_are_owner_private(): void
    {
        $a = $this->runTool('prepare_purchase_from_invoice', $this->invoice())['action'];
        DB::table('products')->where('id', 1)->update(['purchase_conversion_factor' => 10]);
        try {
            $this->confirm($a);
            $this->fail('Changed product accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('ai_purchase_drafts', 0);
    }

    public function test_read_tools_do_not_write_and_sdk_serializes_all_eight_schemas(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        foreach (['check_business_data_integrity', 'get_restock_suggestions', 'get_slow_moving_products', 'get_outstanding_accounts', 'check_daily_closing'] as $tool) {
            $this->runTool($tool);
        }
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select /i', $sql);
        }
        config(['ai.providers.deepseek.key' => 'fake-key']);
        Http::fake(['api.deepseek.com/*' => function ($request) {
            $names = array_column(array_column($request['tools'], 'function'), 'name');
            foreach (array_keys(AiBusinessAdvisor::TOOLS) as $name) {
                $this->assertContains($name, $names);
            }
            $this->assertSame(count($names), count(array_unique($names)));

            return Http::response(['id' => 'advisor-test', 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Ready.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12]]);
        }]);
        $this->assertSame('Ready.', (new MkposAssistant($this->owner))->prompt('Which checks are available?', provider: 'deepseek', model: 'deepseek-flash')->text);
    }

    public function test_draft_http_access_is_owner_scoped_and_blocks_changed_units(): void
    {
        $action = $this->runTool('prepare_purchase_from_invoice', $this->invoice())['action'];
        $saved = $this->confirm($action);
        $id = $saved['result']['purchase_draft_id'];
        $this->actingAs($this->owner, 'web');
        $path = '/api/ai-assistant/purchase-drafts/'.$id;
        $this->getJson($path)->assertOk()->assertJsonPath('posted', false)->assertJsonPath('form.paid_amount', 0);
        DB::table('products')->where('id', 1)->update(['base_unit' => 'Bottle']);
        $this->getJson($path)->assertConflict();
        DB::table('ai_purchase_drafts')->where('id', $id)->update(['user_id' => 999]);
        $this->getJson($path)->assertNotFound();
        $staff = clone $this->owner;
        $staff->role = 'staff';
        $this->actingAs($staff, 'web');
        $this->getJson($path)->assertForbidden();
    }

    public function test_integrity_respects_posting_rounding_and_delivery_fees_and_finds_orphan_lines(): void
    {
        Schema::table('sales', function ($t) {
            $t->string('source')->default('online');
        });
        DB::table('sale_items')->where('business_id', 1)->update(['quantity' => .5, 'unit_price' => 1, 'line_total' => 1]);
        DB::table('sale_items')->insert(['business_id' => 1, 'sale_id' => 1, 'product_id' => 1, 'product_name' => 'Soap', 'quantity' => .5, 'unit_price' => 1, 'line_total' => 1]);
        DB::table('sales')->where('id', 1)->update(['subtotal' => 1, 'total' => 1, 'paid_amount' => 1, 'credit_amount' => 0]);
        $this->assertSame([], $this->runTool('check_business_data_integrity', ['area' => 'sales'])['findings']);
        DB::table('sales')->where('id', 1)->update(['source' => 'ecommerce', 'subtotal' => 2, 'delivery_fee' => 50, 'total' => 52, 'paid_amount' => 52]);
        $this->assertSame([], $this->runTool('check_business_data_integrity', ['area' => 'sales'])['findings']);
        DB::table('sale_items')->insert(['business_id' => 1, 'sale_id' => 2, 'product_id' => 1, 'product_name' => 'Orphan', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);
        $issues = $this->runTool('check_business_data_integrity',['area' => 'sales'])['findings'];
        $this->assertSame('orphan_line',$issues[0]['code']);
        $this->assertSame('detected',$this->runTool('explain_business_issue',['issue_id' => $issues[0]['issue_id']])['status']);
    }
}
