<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesRepresentative;
use App\Services\EcommerceService;
use App\Services\SalesAi\CustomerActions;
use App\Services\SalesAi\PublicTools;
use App\Services\SalesAi\RepresentativeTools;
use App\Services\SalesAi\Settings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesRepresentativeToolsTest extends TestCase
{
    use DatabaseTransactions;

    private array $owner;
    private array $buyer;
    private object $thread;
    private int $product;
    private int $other;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake(); Storage::fake('local'); Http::preventStrayRequests();
        config(['cache.default' => 'array', 'shop_chat.enabled' => true, 'shop_chat.realtime_enabled' => false, 'ai.providers.deepseek.key' => 'fixture-not-a-real-key']);
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => 'fixture', 'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => .3, 'output' => 1.2, 'cached_input' => .006]]], 3600);
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true, 'api_key_encrypted' => null, 'usd_to_mmk_rate' => '4000', 'sales_ai_markup_percent' => '5']);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 10_000_000_000]);
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Representative tools fixture', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $store = $this->putJson('/api/shop-admin/settings', array_replace($store, ['storefront_enabled' => true, 'accepting_orders' => true, 'payment_methods' => [['id' => 'cash', 'name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'provider' => 'manual'], ['id' => 'bank', 'name' => 'Bank', 'type' => 'transfer', 'bank' => 'KBZPay', 'account_name' => 'Shop', 'account_no' => '12345678', 'is_active' => true, 'provider' => 'manual']], 'delivery_zones' => [['id' => 'town', 'name' => 'Town', 'fee' => 500]]]))->assertOk()->json();
        $this->slug = $store['slug'];
        app(EcommerceService::class)->scoped($this->owner['business']['id'], function () {
            foreach ([['name' => 'Soap', 'price' => 100], ['name' => 'Rice', 'price' => 200]] as $p) {
                $id = DB::table('products')->insertGetId($p + ['stock' => 20, 'cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('product_prices')->insert(['product_id' => $id, 'name' => 'Retail', 'price' => $p['price']]);
                DB::table('ecommerce_product_settings')->insert(['business_id' => $this->owner['business']['id'], 'product_id' => $id, 'published' => true, 'unit' => 'base', 'description' => 'Published product description']);
                if ($p['name'] === 'Soap') $this->product = $id; else $this->other = $id;
            }
        });
        $settings = $this->getJson('/api/shop-admin/sales-ai')->assertOk()->json('settings');
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['enabled' => true, 'tool_permissions' => array_keys(Settings::TOOLS), 'sales_policies' => ['returns' => 'Contact the shop within seven days.']]))->assertOk();
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '');
        $this->buyer = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($this->buyer['access_token']);
        $uuid = $this->postJson('/api/ecommerce/stores/'.$this->slug.'/chat')->assertOk()->json('thread.uuid');
        $this->thread = DB::table('shop_chat_threads')->where('uuid', $uuid)->first();
    }

    private function agent(bool $image = false): SalesRepresentative
    {
        $data = ['body' => 'Help me buy products', 'client_id' => (string) Str::uuid()];
        if ($image) $data['image'] = UploadedFile::fake()->image('product.png', 200, 300);
        $message = $this->post('/api/ecommerce/chat/'.$this->thread->uuid.'/messages', $data)->assertOk()->json('message.id');
        $run = DB::table('sales_ai_runs')->where('trigger_message_id', $message)->firstOrFail();
        DB::table('sales_ai_runs')->where('id', $run->id)->update(['status' => 'processing']);
        $thread = DB::table('shop_chat_threads')->where('id', $this->thread->id)->first();
        return new SalesRepresentative($thread, app(Settings::class)->get((int) $thread->business_id), runId: $run->id, messageId: $message);
    }

    private function tool(SalesRepresentative $agent, string $name, array $a = []): array { return app(PublicTools::class)->handle($agent, $name, $a); }
    private function url(string $id): string { return '/api/ecommerce/chat/'.$this->thread->uuid.'/sales-actions/'.$id; }
    private function publish(SalesRepresentative $agent): void
    {
        $message = DB::table('shop_chat_messages')->insertGetId(['thread_id' => $this->thread->id, 'client_id' => (string) Str::uuid(), 'sender' => 'owner', 'is_ai' => true, 'ai_name' => 'Representative', 'body' => 'Review the proposal.', 'created_at' => now()]);
        app(CustomerActions::class)->publish($agent->runId, $message);
    }
    private function confirm(array $action): array
    {
        return $this->postJson($this->url($action['id']).'/confirm', ['revision' => $action['revision'], 'preview_hash' => $action['preview_hash'], 'confirmed' => true])->assertOk()->json();
    }
    private function checkout(SalesRepresentative $agent, string $method = 'cash'): array
    {
        $a = $this->tool($agent, 'prepare_checkout', ['items' => [['product_id' => $this->product, 'quantity' => 2]]]);
        $this->publish($agent);
        $review = $this->getJson($this->url($a['action_id']))->assertOk()->json();
        return $this->postJson($this->url($a['action_id']).'/review', ['revision' => $review['revision'], 'preview_hash' => $review['preview_hash'], 'values' => ['items' => [['product_id' => $this->product, 'quantity' => 2]], 'fulfillment' => 'pickup', 'payment_method_id' => $method, 'customer_name' => 'Buyer', 'phone' => '099999999']])->assertOk()->json();
    }

    public function test_all_schemas_are_available_and_public_catalog_policies_comparison_and_budget_are_verified(): void
    {
        $agent = $this->agent();
        $this->assertCount(21, iterator_to_array((function () use ($agent) { yield from $agent->tools(); })()));
        foreach ($agent->tools() as $tool) $tool->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);
        $p = $this->tool($agent, 'get_product_details', ['product_id' => $this->product]);
        $this->assertSame(100, $p['price']); $this->assertArrayNotHasKey('cost', $p);
        $this->assertCount(2, $this->tool($agent, 'compare_products', ['product_ids' => [$this->product, $this->other]])['items']);
        $this->assertSame([$this->product], array_column($this->tool($agent, 'recommend_products', ['budget_max' => 150])['items'], 'id'));
        $this->assertSame('Contact the shop within seven days.', $this->tool($agent, 'get_sales_policies')['policies']['returns']);
        $this->assertContains('warranty', $this->tool($agent, 'get_sales_policies')['unpublished']);
        $this->assertCount(2, $this->tool($agent, 'get_delivery_options')['payment_methods']);
    }

    public function test_cart_is_invisible_until_delivered_and_requires_exact_customer_confirmation_without_reserving_stock(): void
    {
        $agent = $this->agent();
        $a = $this->tool($agent, 'manage_customer_cart', ['action' => 'update', 'items' => [['product_id' => $this->product, 'quantity' => 3]]]);
        $this->getJson($this->url($a['action_id']))->assertNotFound();
        $this->assertSame([], $this->tool($agent, 'manage_customer_cart', ['action' => 'read'])['items']);
        $this->publish($agent);
        $a = $this->getJson($this->url($a['action_id']))->assertOk()->json();
        $this->postJson($this->url($a['id']).'/confirm', ['revision' => $a['revision'], 'preview_hash' => str_repeat('a', 64), 'confirmed' => true])->assertConflict();
        $this->confirm($a); $this->confirm($a);
        $this->assertSame(3, $this->tool($agent, 'manage_customer_cart', ['action' => 'read'])['items'][0]['quantity']);
        $this->assertSame(0, DB::table('ecommerce_stock_reservations')->where('business_id', $this->thread->business_id)->count());
        $this->assertEquals(20, DB::table('products')->where('id', $this->product)->value('stock'));
    }

    public function test_checkout_quote_and_customer_confirmation_create_one_order_and_do_not_post_a_sale(): void
    {
        $agent = $this->agent();
        $quote = $this->tool($agent, 'calculate_quote', ['items' => [['product_id' => $this->product, 'quantity' => 2]], 'fulfillment' => 'delivery', 'delivery_zone_id' => 'town', 'payment_method_id' => 'cash']);
        $this->assertSame(700, $quote['total']);
        $a = $this->checkout($agent);
        $this->assertSame(200, $a['preview']['total']);
        $this->assertSame('pending', $this->tool($agent, 'submit_confirmed_order', ['action_id' => $a['id']])['status']);
        $done = $this->confirm($a); $this->confirm($a);
        $this->assertTrue($done['result']['order_created']);
        $this->assertArrayNotHasKey('tracking_credential', $done['result']);
        $this->assertSame(1, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
        $this->assertSame(1, DB::table('ecommerce_stock_reservations')->where('business_id', $this->thread->business_id)->where('status', 'active')->count());
        $this->assertSame(0, DB::table('sales')->where('business_id', $this->thread->business_id)->count());
        $this->assertSame('completed', $this->tool($agent, 'submit_confirmed_order', ['action_id' => $a['id']])['status']);
    }

    public function test_changed_prices_and_customer_identity_cannot_bypass_checkout_review(): void
    {
        $agent = $this->agent(); $a = $this->checkout($agent);
        DB::table('product_prices')->where('business_id', $this->thread->business_id)->where('product_id', $this->product)->update(['price' => 120]);
        $this->postJson($this->url($a['id']).'/confirm', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
        $this->withToken($this->owner['access_token'])->getJson($this->url($a['id']))->assertUnauthorized();
    }

    public function test_image_product_matches_are_only_candidates_and_cannot_read_another_message(): void
    {
        $agent = $this->agent(true);
        $matches = $this->tool($agent, 'find_product_from_image', ['query' => 'Soap']);
        $this->assertTrue($matches['customer_confirmation_required']); $this->assertCount(1, $matches['items']);
        $this->assertSame(0, DB::table('sales_ai_carts')->where('business_id', $this->thread->business_id)->count());
    }

    public function test_payment_proof_needs_an_image_review_and_stays_pending_shop_verification(): void
    {
        $agent = $this->agent(); $order = $this->confirm($this->checkout($agent, 'bank'))['result']['reference'];
        $agent = $this->agent(true);
        $id = $this->tool($agent, 'submit_order_payment_proof', ['reference' => $order])['action_id'];
        $this->publish($agent);
        $a = $this->getJson($this->url($id))->assertOk()->json();
        $this->postJson($this->url($id).'/confirm', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'confirmed' => true])->assertUnprocessable();
        $a = $this->post($this->url($id).'/review', ['revision' => (string) $a['revision'], 'preview_hash' => $a['preview_hash'], 'values' => '{}'])->assertOk()->assertJsonPath('has_image', true)->json();
        $this->get($this->url($id).'/image')->assertOk();
        $result = $this->confirm($a); $this->confirm($a);
        $this->assertSame('proof_submitted', $result['result']['payment_status']);
        $this->assertFalse($result['result']['payment_verified']);
        $event = json_decode(DB::table('shop_chat_messages')->where('thread_id', $this->thread->id)->where('sales_ai_action_event->action_id', $id)->orderByDesc('id')->value('sales_ai_action_event'), true);
        $this->assertSame('completed', $event['outcome']);
        $this->assertSame('proof_submitted', $event['result']['payment_status']);
        $this->assertFalse($event['result']['payment_verified']);
        $native = DB::table('ecommerce_orders')->where('reference', $order)->first();
        $this->assertSame(1, DB::table('ecommerce_payments')->where('order_id', $native->id)->where('type', 'proof')->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('ecommerce_payments')->where('order_id', $native->id)->where('type', 'confirm')->count());
    }

    public function test_order_change_records_exact_requested_values_for_owner_review_without_editing_the_order(): void
    {
        $agent = $this->agent(); $reference = $this->confirm($this->checkout($agent))['result']['reference'];
        $agent = $this->agent();
        $id = $this->tool($agent, 'request_order_change', ['reference' => $reference, 'reason' => 'Please change the delivery details', 'changes' => ['address' => 'New requested address', 'items' => [['product_id' => $this->product, 'quantity' => 3]]]])['action_id'];
        $this->publish($agent); $a = $this->getJson($this->url($id))->assertOk()->json(); $done = $this->confirm($a);
        $this->assertSame('pending', $done['result']['request_status']);
        $request = DB::table('shop_order_requests')->where('id', $done['result']['request_id'])->first();
        $this->assertSame('New requested address', json_decode($request->requested_changes, true)['address']);
        $order = DB::table('ecommerce_orders')->where('reference', $reference)->first();
        $this->assertNull($order->address); $this->assertEquals(200, $order->total);
        $status = $this->tool($agent, 'get_customer_request_status', ['reference' => $reference]);
        $this->assertSame('change', $status['requests'][0]->type);
        $this->withToken($this->owner['access_token'])->withHeader('X-MKPOS-Auth', 'token');
        $path = '/api/shop-admin/orders/'.$order->id.'/requests/'.$request->id;
        $this->postJson($path, ['status' => 'approved', 'staff_note' => 'Handle with customer'])->assertUnprocessable();
        $this->postJson($path, ['status' => 'resolved', 'staff_note' => 'Customer contacted to arrange a replacement checkout'])->assertOk();
        $this->assertDatabaseHas('shop_order_requests', ['id' => $request->id, 'status' => 'resolved']);
    }

    public function test_cancellation_uses_the_native_unpaid_pending_rule_and_returns_only_create_a_pending_request(): void
    {
        $agent = $this->agent(); $reference = $this->confirm($this->checkout($agent))['result']['reference'];
        $agent = $this->agent(); $id = $this->tool($agent, 'request_cancellation', ['reference' => $reference, 'reason' => 'I no longer need these products'])['action_id'];
        $this->publish($agent); $a = $this->getJson($this->url($id))->assertOk()->json();
        $this->assertTrue($a['preview']['immediate_cancellation']);
        $done = $this->confirm($a); $this->assertSame('approved', $done['result']['request_status']);
        $this->assertDatabaseHas('ecommerce_orders', ['reference' => $reference, 'status' => 'cancelled']);
        $this->assertEquals(20, DB::table('products')->where('id', $this->product)->value('stock'));
        $agent = $this->agent(); $reference = $this->confirm($this->checkout($agent))['result']['reference'];
        DB::table('ecommerce_orders')->where('reference', $reference)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $agent = $this->agent(); $id = $this->tool($agent, 'request_return', ['reference' => $reference, 'reason' => 'The received item is damaged'])['action_id'];
        $this->publish($agent); $a = $this->getJson($this->url($id))->assertOk()->json(); $done = $this->confirm($a);
        $this->assertSame('pending', $done['result']['request_status']);
        $this->assertDatabaseHas('ecommerce_orders', ['reference' => $reference, 'status' => 'completed', 'payment_status' => 'paid']);
    }

    public function test_buy_again_reduces_quantities_to_current_availability_and_customer_owns_all_details(): void
    {
        $agent = $this->agent(); $reference = $this->confirm($this->checkout($agent))['result']['reference'];
        DB::table('products')->where('id', $this->product)->update(['stock' => 3.5]);
        $agent = $this->agent();
        $details = $this->tool($agent, 'get_customer_order_details', ['reference' => $reference]);
        $this->assertSame($reference, $details['reference']); $this->assertArrayNotHasKey('tracking_credential', $details);
        $id = $this->tool($agent, 'prepare_buy_again', ['reference' => $reference])['action_id'];
        $this->publish($agent); $a = $this->getJson($this->url($id))->assertOk()->json();
        $this->assertEquals(1.5, $a['preview']['items'][0]['quantity']); $this->assertEquals(2, $a['preview']['adjustments'][0]['previous_quantity']);
        $this->confirm($a);
        $this->assertEquals(1.5, $this->tool($agent, 'manage_customer_cart', ['action' => 'read'])['items'][0]['quantity']);
        $this->withHeader('Authorization', '');
        $other = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Other buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($other['access_token'])->getJson($this->url($id))->assertNotFound();
    }

    public function test_handoff_cancels_authority_and_expiry_prunes_private_review_images(): void
    {
        $agent = $this->agent(); $reference = $this->confirm($this->checkout($agent, 'bank'))['result']['reference'];
        $agent = $this->agent(); $id = $this->tool($agent, 'submit_order_payment_proof', ['reference' => $reference])['action_id'];
        $this->publish($agent); $a = $this->getJson($this->url($id))->assertOk()->json();
        $a = $this->post($this->url($id).'/review', ['revision' => (string) $a['revision'], 'preview_hash' => $a['preview_hash'], 'values' => '{}', 'image' => UploadedFile::fake()->image('receipt.png')])->assertOk()->json();
        $paths = Storage::disk('local')->allFiles('sales-ai-action-proofs'); $this->assertCount(1, $paths);
        DB::table('shop_chat_threads')->where('id', $this->thread->id)->update(['sales_ai_mode' => 'human', 'sales_ai_version' => DB::raw('sales_ai_version + 1')]);
        $this->postJson($this->url($id).'/confirm', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'confirmed' => true])->assertConflict();
        DB::table('sales_ai_actions')->where('id', $id)->update(['expires_at' => now('UTC')->subMinute()]);
        app(CustomerActions::class)->prune();
        Storage::disk('local')->assertMissing($paths[0]);
        $this->getJson($this->url($id))->assertOk()->assertJsonPath('status', 'expired');
    }

    public function test_distinct_checkout_proposals_cannot_both_commit_from_the_same_cart_version(): void
    {
        $first = $this->checkout($this->agent());
        $second = $this->checkout($this->agent());
        $this->confirm($first);
        $this->postJson($this->url($second['id']).'/confirm', ['revision' => $second['revision'], 'preview_hash' => $second['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertSame(1, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
    }

    public function test_real_sdk_publishes_verified_product_data_and_customer_forms_with_the_reply(): void
    {
        $agent = $this->agent();
        DB::table('sales_ai_runs')->where('id', $agent->runId)->update(['status' => 'pending']);
        $reply = fn ($message, $finish) => ['id' => 'fixture', 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100]];
        Http::fake(['api.deepseek.com/*' => Http::sequence()
            ->push($reply(['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'details', 'type' => 'function', 'function' => ['name' => 'get_product_details', 'arguments' => json_encode(['product_id' => $this->product])]],
                ['id' => 'checkout', 'type' => 'function', 'function' => ['name' => 'prepare_checkout', 'arguments' => json_encode(['items' => [['product_id' => $this->product, 'quantity' => 2]]])]],
            ]], 'tool_calls'))
            ->push($reply(['role' => 'assistant', 'content' => 'Please review the checkout form.'], 'stop'))]);
        app(\App\Services\SalesAi\Runner::class)->process($agent->runId);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $agent->runId, 'status' => 'completed']);
        $message = DB::table('shop_chat_messages')->where('sales_ai_run_id', $agent->runId)->first();
        $this->assertSame($this->product, json_decode($message->sales_ai_content, true)['products'][0]['id']);
        $action = DB::table('sales_ai_actions')->where('run_id', $agent->runId)->first();
        $this->assertEquals($message->id, $action->message_id);
        $this->getJson($this->url($action->id))->assertOk()->assertJsonPath('preview.ready', false);
        $this->getJson('/api/ecommerce/chat/'.$this->thread->uuid.'/messages')->assertOk()->assertJsonPath('items.1.sales_actions.0.id', $action->id)->assertJsonPath('items.1.sales_content.products.0.id', $this->product);
        Http::assertSentCount(2);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
    }

    public function test_checkout_preserves_unordered_cart_items_and_quantities(): void
    {
        $agent = $this->agent();
        $id = $this->tool($agent, 'manage_customer_cart', ['action' => 'update', 'items' => [['product_id' => $this->product, 'quantity' => 5], ['product_id' => $this->other, 'quantity' => 1]]])['action_id'];
        $this->publish($agent);
        $this->confirm($this->getJson($this->url($id))->assertOk()->json());
        $this->confirm($this->checkout($this->agent()));
        $cart = $this->tool($this->agent(), 'manage_customer_cart', ['action' => 'read']);
        $this->assertEquals([3, 1], array_column($cart['items'], 'quantity'));
    }

    public function test_return_image_must_be_reviewed_and_is_copied_to_the_native_request(): void
    {
        $reference = $this->confirm($this->checkout($this->agent()))['result']['reference'];
        DB::table('ecommerce_orders')->where('reference', $reference)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $agent = $this->agent(true);
        $id = $this->tool($agent, 'request_return', ['reference' => $reference, 'reason' => 'The received product is damaged', 'image_message_id' => $agent->messageId])['action_id'];
        $this->publish($agent);
        $a = $this->getJson($this->url($id))->assertOk()->json();
        $this->assertFalse($a['preview']['ready']);
        $this->postJson($this->url($id).'/confirm', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'confirmed' => true])->assertUnprocessable();
        $a = $this->postJson($this->url($id).'/review', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'values' => ['reason' => 'The received product is damaged']])->assertOk()->json();
        $done = $this->confirm($a);
        $row = DB::table('shop_order_requests')->where('id', $done['result']['request_id'])->first();
        $this->assertSame('pending', $row->status);
        Storage::disk('local')->assertExists($row->photo_path);
    }

    public function test_undelivered_actions_are_cancelled_and_changed_permissions_block_confirmation(): void
    {
        $agent = $this->agent();
        $id = $this->tool($agent, 'manage_customer_cart', ['action' => 'clear'])['action_id'];
        app(CustomerActions::class)->publish($agent->runId, null);
        $this->assertDatabaseHas('sales_ai_actions', ['id' => $id, 'status' => 'cancelled', 'message_id' => null]);
        $agent = $this->agent(); $a = $this->checkout($agent);
        app(Settings::class)->update((int) $this->thread->business_id, array_replace($agent->settings, ['tool_permissions' => ['search_products']]));
        $this->postJson($this->url($a['id']).'/confirm', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
    }

    private function feedbackReply(string $text): array
    {
        return ['id' => 'feedback', 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 80, 'total_tokens' => 1080]];
    }

    private function feedbackRuns(string $id): \Illuminate\Support\Collection
    {
        $messages = DB::table('shop_chat_messages')->where('thread_id', $this->thread->id)->where('sales_ai_action_event->action_id', $id)->pluck('id');
        return DB::table('sales_ai_runs')->whereIn('trigger_message_id', $messages)->orderBy('created_at')->get();
    }

    public function test_confirmed_form_triggers_one_real_ai_follow_up_from_the_verified_result(): void
    {
        $a = $this->checkout($this->agent()); $done = $this->confirm($a); $this->confirm($a);
        $runs = $this->feedbackRuns($a['id']); $this->assertCount(2, $runs);
        Http::fake(['api.deepseek.com/*' => Http::response($this->feedbackReply('Your order '.$done['result']['reference'].' is placed. Payment is awaiting shop verification.'))]);
        foreach ($runs as $run) app(\App\Services\SalesAi\Runner::class)->process($run->id);
        Http::assertSentCount(1);
        Http::assertSent(function ($r) use ($done) {
            $data = $r->data();
            $this->assertEqualsCanonicalizing(['get_customer_order_details', 'get_customer_request_status', 'request_human'], array_column(array_column($data['tools'], 'function'), 'name'));
            return str_contains(json_encode($data['messages']), $done['result']['reference']) && str_contains(json_encode($data['messages']), 'order_created');
        });
        $reply = DB::table('shop_chat_messages')->whereIn('sales_ai_run_id', $runs->pluck('id'))->where('is_ai', true)->first();
        $this->assertStringContainsString($done['result']['reference'], $reply->body);
        $this->assertSame(1, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
        $this->assertSame(1, DB::table('sales_ai_entries')->whereIn('run_id', $runs->pluck('id'))->count());
        $this->getJson('/api/ecommerce/chat/'.$this->thread->uuid.'/messages')->assertOk()->assertSee('Form activity: checkout submitted successfully.')->assertSee('Your order');
    }

    public function test_validation_feedback_is_deduplicated_and_forged_previews_do_not_trigger_paid_work(): void
    {
        $agent = $this->agent(); $id = $this->tool($agent, 'prepare_checkout', ['items' => [['product_id' => $this->product, 'quantity' => 2]]])['action_id'];
        $this->publish($agent); $a = $this->getJson($this->url($id))->assertOk()->json();
        for ($i = 0; $i < 2; $i++) $this->postJson($this->url($id).'/confirm', ['revision' => $a['revision'], 'preview_hash' => $a['preview_hash'], 'confirmed' => true])->assertUnprocessable();
        $this->assertCount(1, $this->feedbackRuns($id));
        $this->postJson($this->url($id).'/confirm', ['revision' => 1, 'preview_hash' => str_repeat('b', 64), 'confirmed' => true])->assertConflict();
        $this->assertCount(1, $this->feedbackRuns($id));
        $event = json_decode(DB::table('shop_chat_messages')->where('sales_ai_action_event->action_id', $id)->value('sales_ai_action_event'), true);
        $this->assertSame('failed', $event['outcome']); $this->assertFalse($event['result']['saved']);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
    }

    public function test_cancel_feedback_does_not_cancel_an_order_or_resume_human_handling(): void
    {
        $a = $this->checkout($this->agent());
        $this->postJson($this->url($a['id']).'/cancel')->assertOk();
        $this->postJson($this->url($a['id']).'/cancel')->assertOk();
        $this->assertCount(2, $this->feedbackRuns($a['id']));
        $event = json_decode(DB::table('shop_chat_messages')->where('sales_ai_action_event->action_id', $a['id'])->orderByDesc('id')->value('sales_ai_action_event'), true);
        $this->assertFalse($event['result']['order_cancelled']);
        $a = $this->checkout($this->agent());
        DB::table('shop_chat_threads')->where('id', $this->thread->id)->update(['sales_ai_mode' => 'human', 'sales_ai_version' => DB::raw('sales_ai_version + 1')]);
        $this->postJson($this->url($a['id']).'/cancel')->assertOk();
        $this->assertCount(1, $this->feedbackRuns($a['id'])); // only the earlier review; no run after human takeover
        $this->assertDatabaseHas('shop_chat_threads', ['id' => $this->thread->id, 'sales_ai_mode' => 'human']);
    }

    public function test_a_form_follow_up_cannot_invoke_mutation_tools(): void
    {
        $base = $this->agent();
        $agent = new SalesRepresentative($base->thread, $base->settings, runId: $base->runId, messageId: $base->messageId, actionEvent: ['outcome' => 'completed']);
        $this->assertCount(3, iterator_to_array((function () use ($agent) { yield from $agent->tools(); })()));
        try { $this->tool($agent, 'prepare_checkout', ['items' => [['product_id' => $this->product, 'quantity' => 2]]]); $this->fail('Follow-ups must not prepare mutations'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame(0, DB::table('sales_ai_actions')->where('business_id', $this->thread->business_id)->count());
    }

    public function test_a_new_form_result_suppresses_an_in_flight_preview_reply_without_handing_off(): void
    {
        $a = $this->checkout($this->agent()); $run = $this->feedbackRuns($a['id'])->first();
        Http::fake(['api.deepseek.com/*' => function () use ($a) {
            $this->confirm($a);
            return Http::response($this->feedbackReply('The quote is ready; confirm your order.'));
        }]);
        app(\App\Services\SalesAi\Runner::class)->process($run->id);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $run->id, 'status' => 'cancelled']);
        $this->assertSame(0, DB::table('shop_chat_messages')->where('sales_ai_run_id', $run->id)->count());
        $this->assertDatabaseHas('shop_chat_threads', ['id' => $this->thread->id, 'sales_ai_mode' => 'ai']);
        $this->assertSame(1, DB::table('ecommerce_orders')->where('business_id', $this->thread->business_id)->count());
    }
}
