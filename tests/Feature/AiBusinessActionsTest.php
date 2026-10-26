<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Tools\BusinessActionTool;
use App\Models\User;
use App\Services\AiBusinessActions;
use App\Services\AiBusinessReader;
use App\Services\ProductImportService;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Concerns\AiBusinessFixture;
use Tests\TestCase;

class AiBusinessActionsTest extends TestCase
{
    use AiBusinessFixture;

    private string $conversation;

    private int $messageId;

    private bool $canMutate = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusinessFixture();
        foreach (['2026_10_25_000001_create_ai_assistant_tables.php', '2026_10_25_000004_create_ai_business_actions.php', '2026_10_21_000001_create_product_imports.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('customers', fn (Blueprint $t) => $t->string('email')->nullable());
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true]);
        $this->mock(SubscriptionService::class, function ($mock) {
            $mock->shouldReceive('status')->andReturnUsing(fn () => ['can_mutate' => $this->canMutate]);
        });
        $this->conversation = (string) Str::uuid();
        DB::table('ai_conversations')->insert(['id' => $this->conversation, 'business_id' => 1, 'user_id' => 1, 'title' => 'Business changes', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->messageId = DB::table('ai_messages')->insertGetId(['conversation_id' => $this->conversation, 'role' => 'user', 'body' => 'Make these changes', 'status' => 'completed', 'created_at' => now('UTC')]);
        $this->actingAs($this->owner, 'web');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        $this->travelBack();
        parent::tearDown();
    }

    private function prepare(string $operation, array $input): array
    {
        app(TenantContext::class)->set(1);

        return app(AiBusinessActions::class)->prepare($this->owner, $this->conversation, $this->messageId, $operation, $input);
    }

    private function url(string $id, string $suffix = ''): string
    {
        return '/api/ai-assistant/conversations/'.$this->conversation.'/actions/'.$id.$suffix;
    }

    private function confirm(array $action)
    {
        return $this->postJson($this->url($action['id'], '/confirm'), ['preview_hash' => $action['preview_hash'], 'reviewed_count' => $action['preview']['total']]);
    }

    public function test_settings_changes_require_review_and_owner_confirmation_and_sync_business_profile(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->timestamp('updated_at')->nullable());
        DB::table('storefront_settings')->insert(['business_id' => 1, 'slug' => 'owner-shop', 'display_name' => 'Owner Shop', 'delivery_zones' => '[]', 'payment_methods' => '[]']);
        $action = $this->prepare('manage_app_settings', ['action' => 'update', 'values' => ['shop_name' => 'wyne business', 'receipt_footer' => 'Thank you']]);
        $this->assertNotSame('wyne business', DB::table('businesses')->where('id', 1)->value('name'));
        $this->assertTrue($action['preview']['items'][0]['editable']);
        $this->assertSame('Shop Name', $action['preview']['items'][0]['fields'][0]['label']);
        $this->confirm($action)->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame('wyne business', DB::table('businesses')->where('id', 1)->value('name'));
        $this->assertSame('wyne business', DB::table('storefront_settings')->where('business_id', 1)->value('display_name'));
        $this->assertSame('wyne business', DB::table('settings')->where('business_id', 1)->where('key', 'shop_name')->value('value'));
        $this->confirm($action)->assertOk()->assertJsonPath('status', 'completed');
        $this->assertNotSame('wyne business', DB::table('businesses')->where('id', 2)->value('name'));
    }

    public function test_settings_revision_invalidates_old_proposal_and_requires_separate_confirmation(): void
    {
        $action = $this->prepare('manage_app_settings', ['action' => 'update', 'values' => ['receipt_footer' => 'First']]);
        $next = $this->postJson($this->url($action['id'], '/revise'), ['preview_hash' => $action['preview_hash'], 'values' => ['receipt_footer' => 'Revised']])->assertOk()->json();
        $this->assertNotSame($action['id'], $next['id']);
        $this->assertNotSame($action['preview_hash'], $next['preview_hash']);
        $this->assertDatabaseHas('ai_business_actions', ['id' => $action['id'], 'status' => 'cancelled']);
        $this->assertDatabaseMissing('settings', ['business_id' => 1, 'key' => 'receipt_footer']);
        $this->confirm($action)->assertStatus(409);
        $this->confirm($next)->assertOk();
        $this->assertDatabaseHas('settings', ['business_id' => 1, 'key' => 'receipt_footer', 'value' => 'Revised']);
    }

    public function test_settings_read_excludes_security_and_other_tenants_and_performs_no_writes(): void
    {
        DB::table('settings')->insert([['business_id' => 1, 'key' => 'admin_pin_hash', 'value' => 'SECRET'], ['business_id' => 2, 'key' => 'shop_name', 'value' => 'FOREIGN']]);
        app(TenantContext::class)->set(1);
        $before = DB::table('settings')->count();
        $result = app(AiBusinessReader::class)->run($this->owner, 'get_app_settings', []);
        $this->assertStringNotContainsString('SECRET', json_encode($result));
        $this->assertStringNotContainsString('FOREIGN', json_encode($result));
        $this->assertArrayNotHasKey('admin_pin_hash', $result['data']['values']);
        $this->assertSame($before, DB::table('settings')->count());
    }

    public function test_settings_allowlist_validation_and_stale_access_are_enforced(): void
    {
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->messageId);
        foreach ([['admin_pin' => '1234'], ['role' => 'owner'], ['staff_id' => 2], ['price_types' => 'Wrong'], ['receipt_margin_left' => 99], ['receipt_show_customer' => 'wrong'], ['shop_name' => '   '], ['currency' => '']] as $values) {
            $result = json_decode((new BusinessActionTool($agent, 'manage_app_settings'))->handle(new ToolRequest(['data' => ['action' => 'update', 'values' => $values]])), true);
            $this->assertArrayHasKey('error', $result);
        }
        $action = $this->prepare('manage_app_settings', ['action' => 'update', 'values' => ['receipt_footer' => 'Safe']]);
        DB::table('settings')->insert(['business_id' => 1, 'key' => 'receipt_footer', 'value' => 'Changed outside chat']);
        $this->confirm($action)->assertStatus(409);
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $this->confirm($action)->assertStatus(403);
    }

    public function test_settings_create_and_delete_reset_defaults_without_touching_security(): void
    {
        $created = $this->prepare('manage_app_settings', ['action' => 'create', 'values' => ['printer_name' => 'Counter printer']]);
        $this->confirm($created)->assertOk();
        $reset = $this->prepare('manage_app_settings', ['action' => 'delete', 'keys' => ['printer_name']]);
        $this->assertSame('', $reset['preview']['items'][0]['after']['printer_name']);
        $this->confirm($reset)->assertOk();
        $this->assertDatabaseMissing('settings', ['business_id' => 1, 'key' => 'printer_name']);
        $invalid = $this->postJson($this->url($reset['id'], '/revise'), ['preview_hash' => $reset['preview_hash'], 'values' => ['admin_pin' => '1234']]);
        $invalid->assertStatus(409);
    }

    public function test_each_everyday_operation_prepares_then_saves_once_and_preserves_omitted_fields(): void
    {
        $operations = [
            ['create_product', ['name' => 'New Soap', 'price' => 150, 'cost' => 70], 'products', 'name', 'New Soap'],
            ['update_product', ['record_id' => 1, 'name' => 'Renamed Soap'], 'products', 'name', 'Renamed Soap'],
            ['create_customer', ['name' => 'New Customer', 'email' => 'NEW@EXAMPLE.COM'], 'customers', 'name', 'New Customer'],
            ['update_customer', ['record_id' => 1, 'phone' => '09111'], 'customers', 'phone', '09111'],
            ['create_supplier', ['name' => 'New Supplier'], 'suppliers', 'name', 'New Supplier'],
            ['update_supplier', ['record_id' => 1, 'contact_person' => 'New Contact'], 'suppliers', 'contact_person', 'New Contact'],
            ['create_expense', ['title' => 'Delivery', 'amount' => 250], 'expenses', 'title', 'Delivery'],
            ['update_expense', ['record_id' => 1, 'amount' => 20], 'expenses', 'amount', 20],
        ];
        foreach ($operations as [$operation, $input, $table, $field, $expected]) {
            $before = DB::table($table)->orderBy('id')->get()->toJson();
            $action = $this->prepare($operation, $input);
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
            $this->assertSame('pending', $action['status']);
            $result = $this->confirm($action)->assertOk()->assertJsonPath('status', 'completed')->json();
            $id = $result['result']['record_id'];
            $this->assertEquals($expected, DB::table($table)->where('id', $id)->value($field));
            $count = DB::table($table)->count();
            $this->confirm($action)->assertOk()->assertJsonPath('result.record_id', $id);
            $this->assertSame($count, DB::table($table)->count());
        }
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertEquals(100, DB::table('product_prices')->where('product_id', 1)->value('price'));
        $this->assertSame(0, DB::table('products')->where('name', 'New Soap')->value('stock'));
        $this->assertSame('new@example.com', DB::table('customers')->where('name', 'New Customer')->value('email'));
        $this->assertSame('2026-10-25', DB::table('expenses')->where('title', 'Delivery')->value('expense_date'));
    }

    public function test_model_cannot_confirm_even_with_an_invented_true_or_preview_hash(): void
    {
        $action = $this->prepare('create_customer', ['name' => 'Needs Confirmation']);
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->messageId);
        $tool = new BusinessActionTool($agent, 'execute_confirmed_action');
        $result = json_decode($tool->handle(new ToolRequest(['action_id' => $action['id']])), true);
        $this->assertSame('pending', $result['status']);
        $forged = json_decode($tool->handle(new ToolRequest(['action_id' => $action['id'], 'confirmed' => true, 'preview_hash' => $action['preview_hash']])), true);
        $this->assertSame('action_unavailable', $forged['error']);
        $this->assertFalse(DB::table('customers')->where('name', 'Needs Confirmation')->exists());
        $this->postJson($this->url($action['id'], '/confirm'), ['preview_hash' => str_repeat('0', 64), 'reviewed_count' => 1])->assertConflict();
        $this->postJson($this->url($action['id'], '/confirm'), ['preview_hash' => $action['preview_hash'], 'reviewed_count' => 0])->assertUnprocessable();
    }

    public function test_confirmation_is_owner_conversation_and_business_scoped_and_respects_current_access(): void
    {
        $action = $this->prepare('create_expense', ['title' => 'Transport', 'amount' => 90]);
        $this->canMutate = false;
        $this->confirm($action)->assertStatus(402);
        $this->canMutate = true;
        DB::table('users')->where('id', 1)->update(['role' => 'staff']);
        $this->confirm($action)->assertForbidden();
        DB::table('users')->where('id', 1)->update(['role' => 'owner']);
        $other = (string) Str::uuid();
        DB::table('ai_conversations')->insert(['id' => $other, 'business_id' => 1, 'user_id' => 1, 'title' => 'Other']);
        $this->getJson(str_replace($this->conversation, $other, $this->url($action['id'])))->assertNotFound();
        DB::table('users')->insert(['id' => 2, 'business_id' => 1, 'role' => 'owner']);
        $this->actingAs(User::findOrFail(2), 'web');
        $this->getJson($this->url($action['id']))->assertNotFound();
        $this->actingAs($this->owner, 'web');
        $foreign = $this->prepare('update_supplier', ['record_id' => 1, 'name' => 'Own Supplier']);
        $this->assertSame('pending', $foreign['status']);
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->messageId);
        $result = json_decode((new BusinessActionTool($agent, 'update_supplier'))->handle(new ToolRequest(['data' => ['record_id' => 2, 'name' => 'Hijacked']])), true);
        $this->assertSame(404, $result['status']);
        config(['mkpos.enabled_modules' => ['products']]);
        $this->confirm($action)->assertForbidden();
        $this->assertFalse(DB::table('expenses')->where('title', 'Transport')->exists());
    }

    public function test_changed_records_expiry_cancel_and_repeated_preparation_are_safe(): void
    {
        $action = $this->prepare('update_customer', ['record_id' => 1, 'name' => 'New Name']);
        $repeat = $this->prepare('update_customer', ['name' => 'New Name', 'record_id' => 1]);
        $this->assertSame($action['id'], $repeat['id']);
        DB::table('customers')->where('id', 1)->update(['phone' => 'Changed elsewhere']);
        $this->confirm($action)->assertConflict();
        $this->assertSame('Customer One', DB::table('customers')->where('id', 1)->value('name'));
        $this->postJson($this->url($action['id'], '/cancel'))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->confirm($action)->assertConflict();
        $action = $this->prepare('create_supplier', ['name' => 'Expired Supplier']);
        $this->travel(31)->minutes();
        $this->getJson($this->url($action['id']))->assertOk()->assertJsonPath('status', 'expired');
        $this->confirm($action)->assertConflict();
        $this->assertFalse(DB::table('suppliers')->where('name', 'Expired Supplier')->exists());
    }

    public function test_named_bulk_prices_preview_exact_targets_and_preserve_other_prices_and_stock(): void
    {
        DB::table('product_prices')->insert(['business_id' => 1, 'product_id' => 1, 'name' => 'Wholesale', 'price' => 80]);
        DB::table('products')->where('id', 1)->update(['category' => 'Beverages']);
        $action = $this->prepare('update_product_prices', ['category' => 'Beverages', 'price_type' => 'Retail', 'percentage' => 5]);
        $this->assertSame(105, $action['preview']['items'][0]['after']['price']);
        $this->assertEquals(100, $action['preview']['items'][0]['before']['price']);
        $this->confirm($action)->assertOk();
        $this->assertEquals(105, DB::table('products')->where('id', 1)->value('price'));
        $this->assertEquals(80, DB::table('product_prices')->where('product_id', 1)->where('name', 'Wholesale')->value('price'));
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('stock'));
        $this->assertEquals(100, DB::table('products')->where('id', 2)->value('price'));
        $action = $this->prepare('update_product_prices', ['changes' => [['product_id' => 1, 'price_type' => 'Wholesale', 'price' => 85]]]);
        DB::table('product_prices')->where('product_id', 1)->where('name', 'Retail')->update(['price' => 110]);
        $this->confirm($action)->assertConflict();
        $this->assertEquals(80, DB::table('product_prices')->where('product_id', 1)->where('name', 'Wholesale')->value('price'));
    }

    public function test_import_is_reviewed_then_atomic_and_replay_does_not_repeat_opening_stock(): void
    {
        $import = app(ProductImportService::class)->preview("Name,SKU,Cost,Retail,Stock\nImported Soap,NEW-1,50,100,3\n", 'products.csv', 1);
        $action = $this->prepare('execute_product_import', ['import_id' => $import['id']]);
        $this->assertSame(3, $action['preview']['items'][0]['after']['stock']);
        $this->assertFalse(DB::table('products')->where('sku', 'NEW-1')->exists());
        $this->confirm($action)->assertOk()->assertJsonPath('result.import.imported', 1);
        $this->confirm($action)->assertOk();
        $this->assertSame(1, DB::table('products')->where('sku', 'NEW-1')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'opening_stock')->count());
        $this->assertEquals(3, DB::table('products')->where('sku', 'NEW-1')->value('stock'));
    }

    public function test_import_conflict_rolls_back_every_created_product_and_progress_row(): void
    {
        $import = app(ProductImportService::class)->preview("Name,SKU,Retail\nFirst,NEW-A,100\nSecond,NEW-B,100\n", 'products.csv', 1);
        $action = $this->prepare('execute_product_import', ['import_id' => $import['id']]);
        DB::table('products')->insert(['business_id' => 1, 'name' => 'Concurrent product', 'sku' => 'NEW-B']);
        $this->confirm($action)->assertConflict();
        $this->assertFalse(DB::table('products')->where('sku', 'NEW-A')->exists());
        $this->assertSame(2, DB::table('product_import_rows')->where('import_id', $import['id'])->where('status', 'ready')->count());
        $this->assertSame('pending', DB::table('ai_business_actions')->where('id', $action['id'])->value('status'));
    }

    public function test_multi_chunk_import_rolls_back_earlier_checkpoints_on_a_late_conflict(): void
    {
        $csv = "Name,SKU,Retail,Stock\n";
        for ($i = 1; $i <= 52; $i++) {
            $csv .= "Import {$i},CHUNK-{$i},100,1\n";
        }
        $import = app(ProductImportService::class)->preview($csv, 'many-products.csv', 1);
        $action = $this->prepare('execute_product_import', ['import_id' => $import['id']]);
        $this->assertSame(52, $action['preview']['total']);
        $this->getJson($this->url($action['id']).'?limit=20&offset=20')->assertOk()->assertJsonCount(20, 'preview.items')->assertJsonPath('preview.next_offset', 40);
        DB::table('products')->insert(['business_id' => 1, 'name' => 'Late conflict', 'sku' => 'CHUNK-52']);
        $this->confirm($action)->assertConflict();
        $this->assertSame(0, DB::table('products')->where('sku', 'like', 'CHUNK-%')->where('sku', '!=', 'CHUNK-52')->count());
        $this->assertSame(52, DB::table('product_import_rows')->where('import_id', $import['id'])->where('status', 'ready')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'opening_stock')->count());
    }

    public function test_currency_changes_invalidate_a_pending_expense_and_regular_forms_share_validation(): void
    {
        $action = $this->prepare('create_expense', ['title' => 'Review currency', 'amount' => 500]);
        DB::table('businesses')->where('id', 1)->update(['currency' => 'USD']);
        $this->confirm($action)->assertConflict();
        $this->assertFalse(DB::table('expenses')->where('title', 'Review currency')->exists());
        $this->postJson('/api/customers', ['name' => 'Normal form customer', 'email' => 'FORM@EXAMPLE.COM'])->assertOk()->assertJsonPath('email', 'form@example.com');
        $this->postJson('/api/suppliers', ['name' => 'Normal form supplier'])->assertOk()->assertJsonPath('contact_person', '');
        $this->postJson('/api/expenses', ['title' => 'Normal form expense', 'amount' => 25])->assertOk()->assertJsonPath('expense_date', '2026-10-25');
        $this->postJson('/api/expenses', ['title' => 'Invalid expense', 'amount' => -1])->assertUnprocessable();
    }

    public function test_unsafe_fields_and_phase_three_operations_are_not_exposed_or_accepted(): void
    {
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->messageId);
        $names = array_map(fn ($t) => method_exists($t, 'name') ? $t->name() : class_basename($t), $agent->tools());
        $this->assertCount(42, $names);
        $this->assertNotContains('adjust_stock', $names);
        foreach ([['update_product', ['record_id' => 1, 'stock' => 900]], ['create_customer', ['name' => 'Evil', 'business_id' => 2]], ['create_expense', ['title' => 'Bad', 'amount' => -1]]] as [$name, $data]) {
            $result = json_decode((new BusinessActionTool($agent, $name))->handle(new ToolRequest(['data' => $data])), true);
            $this->assertArrayHasKey('error', $result);
        }
        $this->assertSame(0, DB::table('ai_business_actions')->count());
    }

    public function test_sdk_can_prepare_a_proposal_but_only_app_confirmation_saves_it(): void
    {
        config(['ai.providers.deepseek.key' => 'fake-key']);
        $calls = 0;
        Http::fake(['api.deepseek.com/*' => function ($request) use (&$calls) {
            $calls++;
            $name = $calls === 1 ? 'get_business_context' : 'create_customer';
            $message = $calls === 3 ? ['role' => 'assistant', 'content' => 'Review the proposed customer and press Confirm changes.'] : ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'proposal-'.$calls, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $calls === 1 ? '{}' : json_encode(['data' => ['name' => 'Proposed Customer']])]]]];

            return Http::response(['id' => 'proposal-response-'.$calls, 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $calls === 3 ? 'stop' : 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]);
        }]);
        $agent = new MkposAssistant($this->owner, conversationId: $this->conversation, messageId: $this->messageId);
        $result = $agent->prompt('Add Proposed Customer', provider: 'deepseek', model: 'deepseek-flash');
        $this->assertStringContainsString('Confirm changes', $result->text);
        $this->assertSame(['get_business_context', 'create_customer'], $agent->usedTools);
        $this->assertFalse(DB::table('customers')->where('name', 'Proposed Customer')->exists());
        $actions = $this->getJson('/api/ai-assistant/conversations/'.$this->conversation.'/actions')->assertOk()->assertJsonCount(1, 'items')->json();
        $this->confirm($actions['items'][0])->assertOk();
        $this->assertSame(1, DB::table('customers')->where('name', 'Proposed Customer')->count());
    }
}
