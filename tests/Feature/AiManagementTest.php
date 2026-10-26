<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessManagementTool;
use App\Services\AiBusinessActions;
use App\Services\AiManagementReader;
use App\Services\AiPriceRuleActions;
use App\Services\ShopChatRealtime;
use App\Services\ShopCustomerAccess;
use App\Services\ShopCustomerNotifications;
use App\Services\ShopPaymentMethods;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\AiBusinessFixture;
use Tests\Support\TenantSqliteConnection;
use Tests\TestCase;

class AiManagementTest extends TestCase
{
    use AiBusinessFixture;

    private string $conversation;

    private int $message;

    private function nativeFixture(): void
    {
        foreach (['2026_08_07_050000_add_offline_sync_fields_to_sales.php', '2026_09_17_000000_add_photo_path_to_products.php', '2026_10_16_030000_add_purchase_reference_dates.php', '2026_10_05_030000_create_shop_owner_locations.php', '2026_10_05_040000_share_business_storefront_profile.php', '2026_10_05_050000_create_shop_contacts.php', '2026_10_05_000001_add_platform_customer_accounts.php', '2026_10_08_000003_create_shop_reviews.php', '2026_10_05_000008_create_shop_customer_controls.php', '2026_10_07_000002_add_shop_promotions.php', '2026_10_09_000001_add_shop_customer_features.php', '2026_10_16_100000_add_block_reason_to_shop_customer_controls.php', '2026_10_05_000009_add_email_to_pos_customers.php', '2026_10_05_020000_add_delivery_fee_recipient.php', '2026_10_18_000001_create_marketplace_product_categories.php', '2026_10_16_120000_add_pickup_details_to_storefront_settings.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('ecommerce_orders', fn ($t) => $t->timestamp('expires_at')->nullable()->change());
        Schema::table('businesses', function ($t) {
            $t->string('slug')->default('shop');
            $t->timestamps();
        });
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        $pdo->sqliteCreateFunction('SHA2', fn ($value, $bits) => hash('sha256', $value));
        DB::extend('tenant_sqlite', fn ($config) => new TenantSqliteConnection($pdo, ':memory:', '', $config));
        config(['database.connections.management_test' => ['driver' => 'tenant_sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('management_test');
    }

    private function propose(string $operation, array $input): array
    {
        return app(AiBusinessActions::class)->prepare($this->owner, $this->conversation, $this->message, $operation, $input);
    }

    private function approve(array $a, array $verification = []): array
    {
        return app(AiBusinessActions::class)->confirm($this->owner, $this->conversation, $a['id'], $a['preview_hash'], $a['preview']['total'], $verification);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
        foreach (['2026_10_25_000001_create_ai_assistant_tables.php', '2026_10_25_000004_create_ai_business_actions.php', '2026_10_25_000017_create_ai_management_audits.php'] as $m) {
            (require database_path('migrations/'.$m))->up();
        }
        DB::table('ai_assistant_settings')->update(['enabled' => true]);
        $this->mock(SubscriptionService::class, fn ($m) => $m->shouldReceive('status')->andReturn(['can_mutate' => true, 'is_valid' => true, 'access_type' => 'paid']));
        $this->conversation = (string) Str::uuid();
        DB::table('ai_conversations')->insert(['id' => $this->conversation, 'business_id' => 1, 'user_id' => 1, 'title' => 'Manage']);
        $this->message = DB::table('ai_messages')->insertGetId(['conversation_id' => $this->conversation, 'role' => 'user', 'body' => 'Set wholesale 15%, retail 20% markup', 'status' => 'completed']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function prepare(array $changes): array
    {
        return app(AiBusinessActions::class)->prepare($this->owner, $this->conversation, $this->message, 'manage_price_types', ['changes' => $changes]);
    }

    private function confirm(array $a): array
    {
        return app(AiBusinessActions::class)->confirm($this->owner, $this->conversation, $a['id'], $a['preview_hash'], $a['preview']['total']);
    }

    public function test_wholesale_and_retail_rules_apply_only_after_exact_owner_approval_and_are_tenant_scoped(): void
    {
        $beforeId = DB::table('product_prices')->where('business_id', 1)->value('id');
        $a = $this->prepare([['action' => 'create', 'name' => 'Wholesale', 'pricing_mode' => 'automatic', 'markup_percent' => 15, 'apply_to_existing' => true], ['action' => 'update', 'name' => 'Retail', 'pricing_mode' => 'automatic', 'markup_percent' => 20, 'apply_to_existing' => true]]);
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => 1, 'name' => 'Wholesale']);
        $this->assertEquals(100, DB::table('products')->where('id', 1)->value('price'));
        $this->assertSame(4, $a['preview']['total']);
        $done = $this->confirm($a);
        $this->assertEquals(48, DB::table('products')->where('id', 1)->value('price'));
        $this->assertEquals(46, DB::table('product_prices')->where('business_id', 1)->where('name', 'Wholesale')->value('price'));
        $this->assertEquals(100, DB::table('products')->where('id', 2)->value('price'));
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => 2, 'name' => 'Wholesale']);
        $this->assertSame($beforeId, DB::table('product_prices')->where('business_id', 1)->where('name', 'Retail')->value('id'));
        $this->assertSame($done['result'], $this->confirm($a)['result']);
        $this->assertDatabaseCount('ai_management_audits', 1);
    }

    public function test_manual_prices_are_preserved_without_bulk_apply_and_rule_changes_can_be_cancelled(): void
    {
        $a = $this->prepare([['action' => 'update', 'name' => 'Retail', 'pricing_mode' => 'automatic', 'markup_percent' => 20]]);
        $this->assertSame(1, $a['preview']['total']);
        $this->confirm($a);
        $this->assertEquals(100, DB::table('product_prices')->where('business_id', 1)->value('price'));
        $this->assertEquals(1, DB::table('product_prices')->where('business_id', 1)->value('is_manual'));
        $a = $this->prepare([['action' => 'create', 'name' => 'Wholesale', 'pricing_mode' => 'automatic', 'markup_percent' => 15]]);
        app(AiBusinessActions::class)->cancel($this->owner, $this->conversation, $a['id']);
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => 1, 'name' => 'Wholesale']);
    }

    public function test_price_rule_deletion_guards_and_stale_costs_fail_without_partial_changes(): void
    {
        try {
            $this->prepare([['action' => 'delete', 'name' => 'Retail']]);
            $this->fail('Last rule deleted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $a = $this->prepare([['action' => 'create', 'name' => 'Wholesale', 'pricing_mode' => 'automatic', 'markup_percent' => 15, 'apply_to_existing' => true]]);
        DB::table('products')->where('id', 1)->update(['cost' => 100]);
        try {
            $this->confirm($a);
            $this->fail('Stale cost accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => 1, 'name' => 'Wholesale']);
        $this->assertDatabaseCount('ai_management_audits', 0);
    }

    public function test_price_rule_reads_have_no_side_effects_and_do_not_expose_other_businesses(): void
    {
        DB::table('price_type_rules')->insert(['business_id' => 2, 'name' => 'Private Other Business']);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $data = app(AiPriceRuleActions::class)->read();
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select /i', $sql);
        }
        $this->assertStringNotContainsString('Private Other Business', json_encode($data));
    }

    public function test_native_sale_posts_only_on_approval_and_pin_protects_deletion(): void
    {
        $this->nativeFixture();
        DB::table('settings')->insert(['key' => 'admin_pin_hash', 'value' => password_hash('4567', PASSWORD_DEFAULT)]);
        $a = $this->propose('manage_sales', ['action' => 'create', 'data' => ['customer_id' => 1, 'payment_method' => 'Cash', 'paid_amount' => 20, 'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 100]]]]);
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $done = $this->approve($a);
        $id = $done['result']['record_id'];
        $this->assertDatabaseHas('sales', ['id' => $id, 'business_id' => 1, 'total' => 100, 'credit_amount' => 80]);
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('stock'));
        $delete = $this->propose('manage_sales', ['action' => 'delete', 'record_id' => $id]);
        $this->assertTrue($delete['requires_pin']);
        try {
            $this->approve($delete, ['admin_pin' => '1111']);
            $this->fail('Wrong PIN accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseHas('sales', ['id' => $id]);
        $this->approve($delete, ['admin_pin' => '4567']);
        $this->approve($delete);
        $this->assertDatabaseMissing('sales', ['id' => $id]);
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertDatabaseCount('ai_management_audits', 2);
        $this->assertStringNotContainsString('4567', DB::table('ai_management_audits')->pluck('review')->implode(''));
    }

    public function test_purchase_post_update_delete_uses_native_stock_and_cost_calculation(): void
    {
        $this->nativeFixture();
        $a = $this->propose('manage_purchases', ['action' => 'create', 'data' => ['supplier_id' => 1, 'payment_method' => 'Cash', 'paid_amount' => 0, 'items' => [['product_id' => 1, 'quantity' => 2, 'foc_quantity' => 1, 'unit_cost' => 60]]]]);
        $done = $this->approve($a);
        $id = $done['result']['record_id'];
        $this->assertDatabaseHas('purchases', ['id' => $id, 'business_id' => 1, 'total_cost' => 120, 'credit_amount' => 120, 'payment_method' => 'Credit']);
        $this->assertEquals(5, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertEquals(40, DB::table('products')->where('id', 1)->value('cost'));
        $edit = $this->propose('manage_purchases', ['action' => 'update', 'record_id' => $id, 'data' => ['items' => [['product_id' => 1, 'quantity' => 2, 'unit_cost' => 70]]]]);
        $this->approve($edit);
        $this->assertEquals(4, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertEquals(70, DB::table('products')->where('id', 1)->value('cost'));
        $this->approve($this->propose('manage_purchases', ['action' => 'delete', 'record_id' => $id]));
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertEquals(100, DB::table('products')->where('id', 1)->value('cost'));
    }

    public function test_inventory_settlement_and_archive_restore_are_scoped_and_audited(): void
    {
        $this->nativeFixture();
        $this->approve($this->propose('manage_inventory', ['action' => 'adjust', 'record_id' => 1, 'data' => ['quantity' => 3, 'reason' => 'Physical count']]));
        $this->assertEquals(5, DB::table('products')->where('id', 1)->value('stock'));
        $p = $this->approve($this->propose('manage_account_payments', ['action' => 'create', 'party' => 'customer', 'party_id' => 1, 'data' => ['amount' => 50, 'direction' => 'customer_to_shop', 'note' => 'Collected']]));
        $this->assertDatabaseHas('customer_payments', ['id' => $p['result']['record_id'], 'business_id' => 1, 'amount' => 50]);
        $this->approve($this->propose('manage_account_payments', ['action' => 'update', 'party' => 'customer', 'record_id' => $p['result']['record_id'], 'data' => ['amount' => 40]]));
        $this->approve($this->propose('manage_account_payments', ['action' => 'delete', 'party' => 'customer', 'record_id' => $p['result']['record_id']]));
        $this->approve($this->propose('manage_business_records', ['action' => 'archive', 'type' => 'product', 'record_id' => 1]));
        $this->assertEquals(0, DB::table('products')->where('id', 1)->value('is_active'));
        $this->approve($this->propose('manage_business_records', ['action' => 'restore', 'type' => 'product', 'record_id' => 1]));
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('is_active'));
        $this->assertEquals(2, DB::connection('business_ai_test')->table('products')->where('id', 2)->value('stock'));
    }

    private function shopFixture(): void
    {
        $this->nativeFixture();
        DB::table('shop_owner_locations')->insert(['business_id' => 1, 'owner_id' => 1, 'latitude' => 16.8, 'longitude' => 96.1, 'accuracy' => 10, 'captured_at' => now()]);
        DB::table('storefront_settings')->insert(['business_id' => 1, 'slug' => 'owner-one', 'display_name' => 'Owner One', 'delivery_zones' => '[]', 'payment_methods' => json_encode([ShopPaymentMethods::defaultCash()])]);
        DB::table('price_type_rules')->insert(['name' => 'Retail']);
        $this->mock(ShopChatRealtime::class, fn ($m) => $m->shouldReceive('publish')->andReturnNull());
        $this->mock(ShopCustomerNotifications::class, fn ($m) => $m->shouldReceive('order')->andReturnNull());
    }

    public function test_online_settings_contacts_delivery_methods_and_catalog_use_reviewed_native_contracts(): void
    {
        $this->shopFixture();
        $this->approve($this->propose('manage_shop_settings', ['action' => 'update', 'data' => ['accepting_orders' => true, 'pickup_instructions' => 'Collect at counter']]));
        $this->assertDatabaseHas('storefront_settings', ['business_id' => 1, 'accepting_orders' => 1, 'pickup_instructions' => 'Collect at counter']);
        $this->approve($this->propose('manage_shop_contacts', ['action' => 'create', 'data' => ['platform' => 'phone', 'value' => '0912345678', 'label' => 'Sales']]));
        $id = DB::table('shop_contacts')->where('business_id', 1)->value('id');
        $this->approve($this->propose('manage_shop_contacts', ['action' => 'update', 'record_id' => $id, 'data' => ['label' => 'Orders']]));
        $this->assertDatabaseHas('shop_contacts', ['id' => $id, 'label' => 'Orders']);
        $this->approve($this->propose('manage_shop_contacts', ['action' => 'delete', 'record_id' => $id]));
        $this->assertDatabaseMissing('shop_contacts', ['id' => $id]);
        $this->approve($this->propose('manage_shop_delivery_zones', ['action' => 'create', 'data' => ['name' => 'Downtown', 'fee' => 1000]]));
        $zone = json_decode(DB::table('storefront_settings')->value('delivery_zones'), true)[0];
        $this->approve($this->propose('manage_shop_delivery_zones', ['action' => 'update', 'data' => ['entry_id' => $zone['id'], 'fee' => 1500]]));
        $this->assertSame(1500, json_decode(DB::table('storefront_settings')->value('delivery_zones'), true)[0]['fee']);
        $this->approve($this->propose('manage_shop_delivery_zones', ['action' => 'delete', 'data' => ['entry_id' => $zone['id']]]));
        $this->approve($this->propose('manage_shop_payment_methods', ['action' => 'create', 'data' => ['name' => 'Bank', 'type' => 'transfer', 'account_name' => 'Owner One', 'account_no' => '12345678', 'is_active' => true]]));
        $methods = json_decode(DB::table('storefront_settings')->value('payment_methods'), true);
        $this->assertCount(2, $methods);
        $this->approve($this->propose('manage_shop_payment_methods', ['action' => 'update', 'data' => ['entry_id' => $methods[1]['id'], 'name' => 'Bank transfer']]));
        $this->approve($this->propose('manage_shop_payment_methods', ['action' => 'delete', 'data' => ['entry_id' => $methods[1]['id']]]));
        $this->approve($this->propose('manage_shop_catalog', ['action' => 'update', 'data' => ['items' => [['product_id' => 1, 'published' => true, 'unit' => 'base', 'description' => 'Hand soap']]]]));
        $this->assertDatabaseHas('ecommerce_product_settings', ['business_id' => 1, 'product_id' => 1, 'published' => 1, 'description' => 'Hand soap']);
    }

    public function test_online_payment_and_order_completion_require_owner_verification_and_post_once(): void
    {
        $this->shopFixture();
        DB::table('ecommerce_orders')->where('id', 1)->update(['payment_method' => json_encode(['name' => 'Cash', 'provider' => 'manual'])]);
        $a = $this->propose('manage_shop_orders', ['action' => 'confirm_payment', 'record_id' => 1, 'data' => ['amount' => 100, 'reference' => 'BANK-123']]);
        $this->assertTrue($a['requires_payment_verification']);
        try {
            $this->approve($a);
            $this->fail('Unverified money accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('ecommerce_payments', ['order_id' => 1, 'type' => 'confirm']);
        $this->approve($a, ['verified_payment' => true]);
        $this->approve($a, ['verified_payment' => true]);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => 1, 'payment_status' => 'paid']);
        $this->assertDatabaseCount('ecommerce_payments', 1);
        $this->approve($this->propose('manage_shop_orders', ['action' => 'transition', 'record_id' => 1, 'data' => ['status' => 'dispatched']]));
        $complete = $this->propose('manage_shop_orders', ['action' => 'transition', 'record_id' => 1, 'data' => ['status' => 'completed']]);
        $this->approve($complete);
        $this->approve($complete);
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertDatabaseHas('sales', ['business_id' => 1, 'receipt_no' => 'E-ORDER-1', 'source' => 'ecommerce']);
        $this->assertDatabaseCount('ai_management_audits', 3);
        $saleId = DB::table('ecommerce_orders')->where('id', 1)->value('sale_id');
        $void = $this->propose('manage_sales', ['action' => 'delete', 'record_id' => $saleId]);
        $this->assertTrue($void['requires_goods_verification']);
        $this->assertSame('refund_pending', $void['preview']['items'][0]['after']['linked_orders'][0]['resulting_payment_status']);
        $this->approve($void, ['verified_goods_return' => true]);
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertDatabaseHas('ecommerce_orders', ['id' => 1, 'sale_voided' => true, 'payment_status' => 'refund_pending']);
    }

    public function test_promotions_customer_controls_and_review_reply_removal(): void
    {
        $this->shopFixture();
        $coupon = $this->approve($this->propose('manage_shop_promotions', ['action' => 'create', 'data' => ['type' => 'coupon', 'name' => 'Welcome', 'code' => 'WELCOME', 'discount_type' => 'percentage', 'discount_value' => 10, 'enabled' => true]]));
        $id = $coupon['result']['record_id'];
        $this->assertDatabaseHas('shop_promotions', ['id' => $id, 'code' => 'WELCOME']);
        $this->approve($this->propose('manage_shop_promotions', ['action' => 'update', 'record_id' => $id, 'data' => ['discount_value' => 15]]));
        $this->approve($this->propose('manage_shop_promotions', ['action' => 'delete', 'record_id' => $id]));
        $this->assertNotNull(DB::table('shop_promotions')->where('id', $id)->value('deleted_at'));
        $key = ShopCustomerAccess::key('091234', null);
        $this->approve($this->propose('manage_shop_customers', ['action' => 'block', 'customer_key' => $key, 'data' => ['reason' => 'Repeated fake orders']]));
        $this->assertDatabaseHas('shop_customer_controls', ['business_id' => 1, 'blocked' => true]);
        $this->approve($this->propose('manage_shop_customers', ['action' => 'unblock', 'customer_key' => $key]));
        $this->approve($this->propose('manage_shop_customers', ['action' => 'link_credit', 'customer_key' => $key, 'data' => ['contact_id' => 1]]));
        $this->assertDatabaseHas('shop_customer_controls', ['business_id' => 1, 'credit_customer_id' => 1]);
        DB::table('platform_customers')->insert(['id' => 1, 'name' => 'Buyer', 'email' => 'buyer@example.com', 'password' => 'not-a-real-password']);
        $id = DB::table('shop_reviews')->insertGetId(['business_id' => 1, 'platform_customer_id' => 1, 'rating' => 5, 'comment' => 'Helpful service']);
        $this->approve($this->propose('manage_shop_reviews', ['action' => 'reply', 'record_id' => $id, 'data' => ['reply' => 'Thank you for shopping with us.']]));
        $this->assertDatabaseHas('shop_reviews', ['id' => $id, 'reply' => 'Thank you for shopping with us.']);
        $this->approve($this->propose('manage_shop_reviews', ['action' => 'reply', 'record_id' => $id, 'data' => ['reply' => '']]));
        $this->assertNull(DB::table('shop_reviews')->where('id', $id)->value('reply'));
    }

    public function test_management_read_catalog_is_private_bounded_and_has_no_mutation_side_effects(): void
    {
        $this->shopFixture();
        $reader = app(AiManagementReader::class);
        foreach (AiManagementReader::RESOURCES as $resource) {
            $result = $reader->read(['resource' => $resource, 'limit' => 1]);
            $this->assertStringNotContainsString('Private Other Business', json_encode($result), $resource);
            $this->assertStringNotContainsString('private-tracking-secret', json_encode($result), $resource);
        }
        $this->assertDatabaseCount('ai_management_audits', 0);
        $this->assertDatabaseCount('ai_business_actions', 0);
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
    }

    public function test_model_cannot_supply_approval_or_cross_tenant_ids_and_role_is_rechecked(): void
    {
        $this->nativeFixture();
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->message);
        foreach (BusinessManagementTool::NAMES as $name) {
            $tool = new BusinessManagementTool($agent, $name);
            $schema = $tool->schema(new JsonSchemaTypeFactory);
            $this->assertNotEmpty($tool->description());
            $this->assertArrayNotHasKey('admin_pin', $schema);
            $this->assertArrayNotHasKey('verified_payment', $schema);
            json_encode($schema, JSON_THROW_ON_ERROR);
        }
        $tool = new BusinessManagementTool($agent, 'manage_inventory');
        $args = ['action' => 'adjust', 'record_id' => 1, 'data' => ['quantity' => 1, 'reason' => 'Count']];
        $result = json_decode($tool->handle(new Request($args + ['verified' => true])), true);
        $this->assertSame(422, $result['status']);
        $foreign = json_decode($tool->handle(new Request(array_replace($args, ['record_id' => 2]))), true);
        $this->assertSame(404, $foreign['status']);
        $a = $this->propose('manage_inventory', $args);
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        try {
            $this->approve($a);
            $this->fail('Old owner session approved');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
    }

    public function test_return_cancellation_refund_and_resolution_keep_native_goods_and_payment_gates(): void
    {
        $this->shopFixture();
        DB::table('ecommerce_orders')->where('id', 1)->update(['status' => 'return_pending', 'payment_status' => 'paid']);
        DB::table('ecommerce_stock_reservations')->insert(['business_id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 1]);
        $a = $this->propose('manage_shop_orders', ['action' => 'transition', 'record_id' => 1, 'data' => ['status' => 'cancelled', 'reason' => 'Goods returned']]);
        $this->assertTrue($a['requires_goods_verification']);
        try {
            $this->approve($a);
            $this->fail('Unreturned goods accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->approve($a, ['verified_goods_return' => true]);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => 1, 'status' => 'cancelled', 'payment_status' => 'refund_pending']);
        $refund = $this->propose('manage_shop_orders', ['action' => 'record_refund', 'record_id' => 1, 'data' => ['amount' => 100, 'reference' => 'RF-1']]);
        $this->approve($refund, ['verified_payment' => true]);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => 1, 'payment_status' => 'refunded']);
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
    }

    public function test_sdk_serializes_management_schemas_and_prepares_price_rules_without_executing(): void
    {
        config(['ai.providers.deepseek.key' => 'fake-key']);
        $calls = 0;
        Http::fake(['api.deepseek.com/*' => function ($r) use (&$calls) {
            $calls++;
            $functions = collect($r['tools'])->pluck('function')->keyBy('name');
            foreach (BusinessManagementTool::NAMES as $name) {
                $this->assertTrue($functions->has($name));
            }
            $this->assertSame('object', $functions['manage_sales']['parameters']['type']);
            $this->assertSame('array', $functions['manage_sales']['parameters']['properties']['data']['properties']['items']['type']);
            $this->assertArrayNotHasKey('verified', $functions['manage_shop_orders']['parameters']['properties']['data']['properties']);
            $this->assertArrayNotHasKey('admin_pin', $functions['manage_sales']['parameters']['properties']['data']['properties']);
            $name = $calls === 1 ? 'get_business_context' : 'manage_price_types';
            $args = $calls === 1 ? [] : ['changes' => [['action' => 'create', 'name' => 'Wholesale', 'pricing_mode' => 'automatic', 'markup_percent' => 15]]];
            $message = $calls < 3 ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'management-'.$calls, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args ?: new \stdClass)]]]] : ['role' => 'assistant', 'content' => 'Review the proposed Wholesale cost markup of 15% and confirm in the app.'];

            return Http::response(['id' => 'response-'.$calls, 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $calls < 3 ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]);
        }]);
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->message);
        $result = $agent->prompt('Create Wholesale with 15% cost markup', provider: 'deepseek', model: 'deepseek-flash');
        $this->assertStringContainsString('Review', $result->text);
        $this->assertSame(3, $calls);
        $this->assertDatabaseHas('ai_business_actions', ['operation' => 'manage_price_types', 'status' => 'pending']);
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => 1, 'name' => 'Wholesale']);
        $this->assertDatabaseCount('ai_management_audits', 0);
    }

    public function test_disabled_module_and_expired_subscription_block_prepared_changes(): void
    {
        $a = $this->prepare([['action' => 'create', 'name' => 'Wholesale', 'pricing_mode' => 'automatic', 'markup_percent' => 15]]);
        $modules = config('mkpos.enabled_modules');
        config(['mkpos.enabled_modules' => array_values(array_diff($modules, ['products']))]);
        try {
            $this->approve($a);
            $this->fail('Disabled module saved');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        config(['mkpos.enabled_modules' => $modules]);
        $this->mock(SubscriptionService::class, fn ($m) => $m->shouldReceive('status')->andReturn(['can_mutate' => false, 'is_valid' => false]));
        try {
            $this->approve($a);
            $this->fail('Expired subscription saved');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(402, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => 1, 'name' => 'Wholesale']);
        $this->assertDatabaseCount('ai_management_audits', 0);
    }

    public function test_timezone_changes_and_supplier_payment_directions_follow_native_values(): void
    {
        $this->nativeFixture();
        $a = $this->propose('manage_business_timezone', ['timezone' => 'Asia/Bangkok']);
        $this->assertSame('Asia/Rangoon', DB::table('businesses')->where('id', 1)->value('timezone'));
        $this->approve($a);
        $this->assertSame('Asia/Bangkok', DB::table('businesses')->where('id', 1)->value('timezone'));
        $a = $this->propose('manage_account_payments', ['action' => 'create', 'party' => 'supplier', 'party_id' => 1, 'data' => ['amount' => 50, 'direction' => 'shop_to_supplier', 'payment_method' => 'Cash']]);
        $this->assertEquals(75, $a['preview']['items'][0]['after']['balance']);
        $done = $this->approve($a);
        $this->assertDatabaseHas('supplier_payments', ['id' => $done['result']['record_id'], 'business_id' => 1, 'amount' => 50, 'direction' => 'shop_to_supplier']);
    }

    public function test_renaming_a_shop_price_type_reviews_and_preserves_the_online_reference(): void
    {
        $this->shopFixture();
        $a = $this->prepare([['action' => 'update', 'name' => 'Retail', 'new_name' => 'Standard']]);
        $this->assertSame('Standard', $a['preview']['items'][0]['after']['price_type']);
        $this->assertDatabaseHas('storefront_settings', ['business_id' => 1, 'price_type' => 'Retail']);
        $this->approve($a);
        $this->assertDatabaseHas('storefront_settings', ['business_id' => 1, 'price_type' => 'Standard']);
        $this->assertDatabaseHas('product_prices', ['business_id' => 1, 'name' => 'Standard', 'price' => 100]);
        $this->assertDatabaseMissing('product_prices', ['business_id' => 1, 'name' => 'Retail']);
    }
}
