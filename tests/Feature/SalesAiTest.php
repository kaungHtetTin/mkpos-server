<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesImageRepresentative;
use App\Ai\Agents\SalesRepresentative;
use App\Ai\Agents\SystemAgent;
use App\Ai\Skills\PaymentScreenshotReview;
use App\Models\PlatformAdmin;
use App\Services\AiUsageReport;
use App\Services\OfficeBusinessDeletion;
use App\Services\SalesAi\Chat;
use App\Services\SalesAi\PublicTools;
use App\Services\SalesAi\Runner;
use App\Services\SalesAi\Settings;
use App\Services\SalesAi\Wallet;
use App\Support\AiLocale;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesAiTest extends TestCase
{
    use DatabaseTransactions;

    private array $owner;

    private array $buyer;

    private int $business;

    private string $thread;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'shop_chat.enabled' => true, 'shop_chat.realtime_enabled' => false, 'ai.providers.deepseek.key' => 'test-sales-key-never-return']);
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => 'fixture', 'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => 0.3, 'output' => 1.2, 'cached_input' => 0.006]]], 3600);
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true, 'api_key_encrypted' => null, 'usd_to_mmk_rate' => '4000', 'sales_ai_markup_percent' => '5']);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 10_000_000_000]);
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Sales AI Fixture', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($this->owner['access_token']);
        $this->business = (int) $this->owner['business']['id'];
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->putJson('/api/shop-admin/settings', array_replace($store, ['storefront_enabled' => true]))->assertOk();
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '');
        $this->buyer = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($this->buyer['access_token']);
        $this->thread = $this->postJson('/api/ecommerce/stores/'.$store['slug'].'/chat')->assertOk()->json('thread.uuid');
    }

    private function enable(array $changes = []): void
    {
        $this->withToken($this->owner['access_token']);
        $current = $this->getJson('/api/shop-admin/sales-ai')->assertOk()->json('settings');
        $this->putJson('/api/shop-admin/sales-ai', array_replace($current, ['enabled' => true, 'name' => 'Mia'], $changes))->assertOk();
        $this->withToken($this->buyer['access_token']);
    }

    private function send(string $body = 'Can you help?', ?string $key = null): string
    {
        $message = $this->postJson('/api/ecommerce/chat/'.$this->thread.'/messages', ['body' => $body, 'client_id' => $key ?? (string) Str::uuid()])->assertOk()->json('message.id');

        return (string) DB::table('sales_ai_runs')->where('business_id', $this->business)->where('trigger_message_id', $message)->value('id');
    }

    public function test_image_only_sales_reply_uses_the_customer_interface_language_from_the_queued_run(): void
    {
        $this->enable();
        DB::table('sales_ai_wallets')->where('business_id', $this->business)->update(['balance_micros' => 10_000_000_000]);
        $fields = ['kind' => 'unrelated', 'summary' => 'Unrelated picture.', 'payment_status' => 'unknown',
            ...array_fill_keys(['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at'], '')];
        SalesImageRepresentative::fake([$fields]);
        $this->post('/api/ecommerce/chat/'.$this->thread.'/messages', ['client_id' => (string) Str::uuid(), 'language' => 'my',
            'image' => UploadedFile::fake()->image('photo.png')], ['Accept' => 'application/json'])->assertOk();
        $run = DB::table('sales_ai_runs')->where('business_id', $this->business)->first();
        $this->assertSame('my', $run->language);
        app(Runner::class)->process($run->id);
        $this->assertSame(AiLocale::text('unrelated', 'my'), DB::table('shop_chat_messages')->where('sales_ai_run_id', $run->id)->value('body'));
        SalesImageRepresentative::assertPrompted(fn ($prompt) => $prompt->agent->preferredLanguage === 'my' && str_contains($prompt->agent->instructions(), 'Customer interface language: my'));
    }

    public function test_sales_image_turns_keep_product_and_shop_payment_skills_without_office_escalation(): void
    {
        $this->enable();
        DB::table('sales_ai_wallets')->where('business_id', $this->business)->update(['balance_micros' => 10_000_000_000]);
        $fields = ['summary' => 'Please review the verified shop choices in this conversation.', 'payment_status' => 'unknown',
            ...array_fill_keys(['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at'], '')];
        SalesImageRepresentative::fake([['kind' => 'product'] + $fields, ['kind' => 'shop_payment'] + $fields]);
        foreach (['product', 'shop_payment'] as $kind) {
            $id = $this->send('Please help with this');
            $run = DB::table('sales_ai_runs')->where('id', $id)->first();
            $path = UploadedFile::fake()->image('product.png')->store('shop-chat-images', 'local');
            DB::table('shop_chat_messages')->where('id', $run->trigger_message_id)->update(['image_path' => $path, 'image_mime' => 'image/png', 'image_expires_at' => now('UTC')->addDay()]);
            app(Runner::class)->process($id);
            $this->assertSame('completed', DB::table('sales_ai_runs')->where('id', $id)->value('status'));
            $this->assertSame($fields['summary'], DB::table('shop_chat_messages')->where('sales_ai_run_id', $id)->value('body'));
        }
        $this->assertSame(0, DB::table('error_reports')->where('business_id', $this->business)->count());
        SalesImageRepresentative::assertPrompted(function ($prompt) {
            $names = collect($prompt->agent->tools())->map(fn ($tool) => $tool->name())->all();
            $this->assertStringContainsString(PaymentScreenshotReview::READING_INSTRUCTIONS, $prompt->agent->instructions());
            $this->assertStringContainsString(PaymentScreenshotReview::SALES_INSTRUCTIONS, $prompt->agent->instructions());
            $this->assertStringNotContainsString('System payment review context:', $prompt->agent->instructions());
            $this->assertContains('find_product_from_image', $names);
            $this->assertContains('submit_order_payment_proof', $names);
            $this->assertContains('request_human', $names);

            return count($prompt->attachments) === 1;
        });
        $thread = DB::table('shop_chat_threads')->where('uuid', $this->thread)->first();
        $settings = app(Settings::class)->get($this->business);
        $settings['tool_permissions'] = [];
        $agent = new SalesImageRepresentative($thread, $settings);
        $this->assertSame(['request_human'], collect($agent->tools())->map(fn ($tool) => $tool->name())->all());
        $this->assertStringContainsString('budget', $agent->instructions());
        $this->assertStringContainsString('Never compare a shop order receipt against MKPOS', $agent->instructions());
        $this->assertStringContainsString('customer confirmation', $agent->instructions());
        $this->assertStringContainsString('Only the shop owner/team confirms actual receipt of funds', $agent->instructions());
        $this->assertStringContainsString('Telegram shop payment proof still goes through', $agent->instructions());
    }

    public function test_image_scope_and_unknown_error_escalation_are_bound_to_this_shop(): void
    {
        $this->enable();
        DB::table('sales_ai_wallets')->where('business_id', $this->business)->update(['balance_micros' => 10_000_000_000]);
        $fields = ['summary' => 'MKPOS internal error needs investigation.', 'payment_status' => 'unknown',
            ...array_fill_keys(['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at'], '')];
        SalesImageRepresentative::fake([['kind' => 'error_unknown'] + $fields, ['kind' => 'unrelated'] + $fields]);
        foreach (['error_unknown', 'unrelated'] as $kind) {
            $id = $this->send('Read the screenshot');
            $run = DB::table('sales_ai_runs')->where('id', $id)->first();
            $image = UploadedFile::fake()->image('error.png');
            $path = $image->store('shop-chat-images', 'local');
            DB::table('shop_chat_messages')->where('id', $run->trigger_message_id)->update(['image_path' => $path,
                'image_mime' => 'image/png', 'image_expires_at' => now('UTC')->addDay()]);
            app(Runner::class)->process($id);
            app(Runner::class)->process($id);
            $this->assertSame('completed', DB::table('sales_ai_runs')->where('id', $id)->value('status'));
            $reply = DB::table('shop_chat_messages')->where('sales_ai_run_id', $id)->value('body');
            $this->assertStringContainsString($kind === 'unrelated' ? 'not related to the MKPOS system' : 'submitted to the admin Office', $reply);
        }
        $this->assertSame(1, DB::table('error_reports')->where('business_id', $this->business)->count());
        $report = DB::table('error_reports')->where('business_id', $this->business)->first();
        $this->assertNull($report->user_id);
        $this->assertSame('shop', $report->app);
        $this->assertCount(1, Storage::disk('local')->allFiles('error-report-images'));
    }

    private function response(): array
    {
        return ['id' => 'reply', 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'I can help you choose a product.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100, 'prompt_cache_hit_tokens' => 200, 'prompt_cache_miss_tokens' => 800]];
    }

    public function test_new_business_has_one_free_grant_and_settings_are_private_versioned_and_permission_bounded(): void
    {
        $this->assertSame(500_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
        app(Wallet::class)->ensure($this->business, newBusiness: true);
        $this->assertSame(1, DB::table('sales_ai_entries')->where('business_id', $this->business)->where('type', 'grant')->count());
        $this->withToken($this->owner['access_token']);
        $settings = $this->getJson('/api/shop-admin/sales-ai')->assertOk()->assertJsonPath('settings.enabled', false)->assertDontSee('test-sales-key-never-return')->json('settings');
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['tool_permissions' => ['delete_products']]))->assertStatus(422);
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['tool_permissions' => []]))->assertOk();
        $this->putJson('/api/shop-admin/sales-ai', $settings)->assertStatus(409);
        $this->withToken($this->buyer['access_token']);
        $this->getJson('/api/shop-admin/sales-ai')->assertUnauthorized();
    }

    public function test_real_sdk_reply_is_async_labelled_cache_priced_and_charged_exactly_once(): void
    {
        $this->enable();
        Http::fake(['api.deepseek.com/*' => Http::response($this->response())]);
        $key = (string) Str::uuid();
        $id = $this->send('Hello', $key);
        $this->assertSame(0, DB::table('shop_chat_messages')->where('thread_id', DB::table('shop_chat_threads')->where('uuid', $this->thread)->value('id'))->where('is_ai', true)->count());
        app(Runner::class)->process($id);
        $this->getJson('/api/ecommerce/chat/'.$this->thread.'/messages')->assertOk()->assertJsonPath('items.1.is_ai', true)->assertJsonPath('items.1.ai_name', 'Mia')->assertJsonPath('thread.owner_active', false);
        $this->assertSame(498_482_960, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
        $this->assertSame(0, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('reserved_micros'));
        $this->send('Hello', $key);
        app(Runner::class)->process($id);
        $this->assertSame(1, DB::table('sales_ai_entries')->where('run_id', $id)->count());
        $this->assertSame(1, DB::table('ai_balance_entries')->where('run_id', $id)->count());
        Http::assertSentCount(1);
    }

    public function test_owner_takeover_during_generation_suppresses_late_reply_and_refunds_business_hold(): void
    {
        $this->enable();
        Http::fake(['api.deepseek.com/*' => function () {
            app(Chat::class)->handoff(DB::table('shop_chat_threads')->where('uuid', $this->thread)->first(), 'human');

            return Http::response($this->response());
        }]);
        $id = $this->send();
        app(Runner::class)->process($id);
        $this->assertSame('cancelled', DB::table('sales_ai_runs')->where('id', $id)->value('status'));
        $this->assertSame(500_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
        $this->assertSame(0, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('reserved_micros'));
        $this->assertSame(0, DB::table('shop_chat_messages')->where('sales_ai_run_id', $id)->count());
        $this->assertSame(1, DB::table('ai_balance_entries')->where('run_id', $id)->count());
        $reported = app(AiUsageReport::class)->runs()->where('input_tokens', 1000)->where('output_tokens', 100)->where('status', 'completed')->count();
        $this->assertGreaterThanOrEqual(1, $reported);
    }

    public function test_config_change_cancels_pending_work_and_clears_public_responding_state(): void
    {
        $this->enable();
        $id = $this->send();
        $this->getJson('/api/ecommerce/chat/'.$this->thread.'/messages')->assertOk()->assertJsonPath('thread.sales_ai.responding', true)->assertDontSee('instructions');
        $this->enable(['enabled' => false]);
        $this->getJson('/api/ecommerce/chat/'.$this->thread.'/messages')->assertOk()->assertJsonPath('thread.sales_ai.mode', 'human')->assertJsonPath('thread.sales_ai.responding', false);
        app(Runner::class)->process($id);
        Http::assertNothingSent();
        $this->assertSame('cancelled', DB::table('sales_ai_runs')->where('id', $id)->value('status'));
        $this->assertSame(500_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
    }

    public function test_pricing_is_snapshotted_before_generation_and_provider_failures_release_credit(): void
    {
        $this->enable();
        Http::fake(['api.deepseek.com/*' => function () {
            DB::table('ai_assistant_settings')->where('id', 1)->update(['usd_to_mmk_rate' => 9000, 'sales_ai_markup_percent' => 50]);

            return Http::response($this->response());
        }]);
        $id = $this->send();
        app(Runner::class)->process($id);
        $this->assertSame(1_517_040, (int) DB::table('sales_ai_runs')->where('id', $id)->value('charge_micros'));
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);
        $id = $this->send('Another question');
        app(Runner::class)->process($id);
        $this->assertSame('failed', DB::table('sales_ai_runs')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('reserved_micros'));
        $this->assertSame(498_482_960, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
    }

    public function test_customer_handoff_is_private_and_disable_or_human_reply_stops_queued_ai(): void
    {
        $this->enable();
        $id = $this->send();
        $this->postJson('/api/ecommerce/chat/'.$this->thread.'/handoff')->assertOk()->assertJsonPath('thread.sales_ai.mode', 'waiting_owner');
        app(Runner::class)->process($id);
        Http::assertNothingSent();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/handoff', ['mode' => 'ai', 'version' => 2])->assertOk();
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/messages', ['body' => 'The owner is here.', 'client_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('thread.sales_ai.mode', 'human');
        foreach (DB::table('sales_ai_runs')->where('business_id', $this->business)->pluck('id') as $run) {
            app(Runner::class)->process($run);
        }
        Http::assertNothingSent();
    }

    public function test_empty_wallet_and_provider_failure_handoff_without_stopping_human_chat(): void
    {
        $this->enable();
        DB::table('sales_ai_wallets')->where('business_id', $this->business)->update(['balance_micros' => 0]);
        $id = $this->send();
        app(Runner::class)->process($id);
        Http::assertNothingSent();
        $this->assertSame('waiting_owner', DB::table('shop_chat_threads')->where('uuid', $this->thread)->value('sales_ai_mode'));
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/messages', ['body' => 'I will assist you.', 'client_id' => (string) Str::uuid()])->assertOk();
    }

    public function test_wallet_holds_are_idempotent_and_cannot_overspend_across_runs(): void
    {
        $this->enable();
        $first = $this->send('One');
        $second = $this->send('Two');
        DB::transaction(function () use ($first, $second) {
            $one = DB::table('sales_ai_runs')->where('id', $first)->first();
            $two = DB::table('sales_ai_runs')->where('id', $second)->first();
            $this->assertTrue(app(Wallet::class)->reserve($one, 300_000_000));
            $this->assertTrue(app(Wallet::class)->reserve($one, 300_000_000));
            $this->assertFalse(app(Wallet::class)->reserve($two, 300_000_000));
            app(Wallet::class)->settle($one, 0, [], billable: false);
            $this->assertTrue(app(Wallet::class)->reserve($two, 300_000_000));
        });
    }

    public function test_existing_business_wallet_is_not_retroactively_granted_and_office_ledger_is_private(): void
    {
        DB::table('sales_ai_entries')->where('business_id', $this->business)->delete();
        DB::table('sales_ai_wallets')->where('business_id', $this->business)->delete();
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/sales-ai/wallet')->assertOk()->assertJsonPath('wallet.balance', '0.000000')->assertJsonCount(0, 'items');
        $this->getJson('/api/office/businesses/'.$this->business.'/sales-ai/wallet')->assertUnauthorized();
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '');
        $admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');
        $this->getJson('/api/office/businesses/'.$this->business.'/sales-ai/wallet')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('wallet.balance', '0.000000');
    }

    public function test_tools_recheck_permissions_and_always_allow_human_escalation(): void
    {
        $this->enable(['tool_permissions' => []]);
        $thread = DB::table('shop_chat_threads')->where('uuid', $this->thread)->first();
        $agent = new SalesRepresentative($thread, app(Settings::class)->get($this->business));
        try {
            app(PublicTools::class)->handle($agent, 'shop_information', []);
            $this->fail('Unpermitted tool was allowed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $result = app(PublicTools::class)->handle($agent, 'request_human', ['reason' => 'Customer needs a person']);
        $this->assertSame('requested', $result['handoff']);
        $this->assertSame('Customer needs a person', $agent->handoffReason);
        app(Chat::class)->handoff($thread, 'human');
        try {
            app(PublicTools::class)->handle($agent, 'request_human', []);
            $this->fail('Stale agent was allowed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_public_tools_expose_only_active_payment_accounts_and_this_customers_orders(): void
    {
        $this->enable();
        DB::table('storefront_settings')->where('business_id', $this->business)->update(['payment_methods' => json_encode([
            ['id' => 'active', 'name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'provider' => 'manual'],
            ['id' => 'hidden', 'name' => 'Retired account', 'type' => 'transfer', 'bank' => 'Private', 'is_active' => false, 'provider' => 'manual'],
        ])]);
        $thread = DB::table('shop_chat_threads')->where('uuid', $this->thread)->first();
        $agent = new SalesRepresentative($thread, app(Settings::class)->get($this->business));
        $tools = app(PublicTools::class);
        $info = $tools->handle($agent, 'shop_information', []);
        $this->assertCount(1, $info['payment_methods']);
        $this->assertSame('Cash', $info['payment_methods'][0]['name']);
        $other = $this->withHeader('Authorization', '')->postJson('/api/ecommerce/customer/register', ['name' => 'Other buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json('customer.id');
        foreach ([(int) $thread->platform_customer_id => 'OWN', (int) $other => 'OTHER'] as $customerId => $reference) {
            DB::table('ecommerce_orders')->insert(['business_id' => $this->business, 'platform_customer_id' => $customerId, 'reference' => $reference.'-'.Str::random(20),
                'origin' => 'storefront', 'customer_name' => 'Fixture', 'phone' => '09123', 'fulfillment' => 'pickup', 'payment_method' => '{}', 'currency' => 'Ks', 'subtotal' => 1000, 'total' => 1000,
                'tracking_hash' => hash('sha256', Str::uuid()), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::uuid()), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $orders = $tools->handle($agent, 'customer_orders', ['platform_customer_id' => $other]);
        $this->assertCount(1, $orders['items']);
        $this->assertStringStartsWith('OWN-', $orders['items'][0]['reference']);
        $this->assertArrayNotHasKey('tracking_hash', $orders['items'][0]);
        $this->assertArrayNotHasKey('phone', $orders['items'][0]);
    }

    public function test_catalog_tool_uses_published_selling_prices_and_excludes_private_inventory_fields(): void
    {
        $this->enable();
        $this->withToken($this->owner['access_token']);
        $product = $this->postJson('/api/products', ['name' => 'Sales fixture rice', 'price' => 1500, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 1500]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $thread = DB::table('shop_chat_threads')->where('uuid', $this->thread)->first();
        $agent = new SalesRepresentative($thread, app(Settings::class)->get($this->business));
        $tools = app(PublicTools::class);
        $items = $tools->handle($agent, 'search_products', ['query' => 'Sales fixture rice'])['items'];
        $this->assertCount(1, $items);
        $this->assertSame((int) $product['id'], (int) $items[0]['id']);
        $this->assertSame(1500, $items[0]['price']);
        foreach (['cost', 'business_id', 'sku', 'stock'] as $private) {
            $this->assertArrayNotHasKey($private, $items[0]);
        }
        $this->putJson('/api/shop-admin/publications/'.$product['id'], ['published' => false, 'unit' => 'base'])->assertOk();
        $this->assertSame([], $tools->handle($agent, 'search_products', ['query' => 'Sales fixture rice'])['items']);
    }

    public function test_permanent_business_deletion_waits_for_processing_and_cleans_sales_ai_data(): void
    {
        $this->enable();
        $id = $this->send();
        DB::table('sales_ai_runs')->where('id', $id)->update(['status' => 'processing', 'started_at' => now('UTC')]);
        $deletion = app(OfficeBusinessDeletion::class);
        try {
            $deletion->delete([$this->business]);
            $this->fail('A processing reply must block permanent deletion.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, DB::table('sales_ai_runs')->where('id', $id)->count());
        app(Runner::class)->fail($id, 'cancelled');
        Storage::disk('local')->put('sales-ai-payment-proofs/'.$this->business.'/fixture.png', 'fixture');
        $deletion->delete([$this->business]);
        foreach (['sales_ai_settings', 'sales_ai_wallets', 'sales_ai_entries', 'sales_ai_runs', 'sales_ai_topups'] as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', $this->business)->count());
        }
        Storage::disk('local')->assertMissing('sales-ai-payment-proofs/'.$this->business.'/fixture.png');
        $this->assertTrue(app(Runner::class)->process($id));
        Http::assertNothingSent();
    }

    public function test_owner_wallet_pagination_and_usage_charts_use_business_midnight_and_only_own_usage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 18:00:00', 'UTC'));
        DB::table('businesses')->where('id', $this->business)->update(['timezone' => 'Asia/Yangon']);
        $other = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', 'token')->postJson('/api/auth/register', ['business_name' => 'Other usage fixture', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        foreach ([[$this->business, 'usage', -1234567, '2026-10-25 17:29:59'], [$this->business, 'usage', -2000000, '2026-10-25 17:30:00'],
            [$this->business, 'top_up', 9000000, '2026-10-25 17:45:00'], [$this->business, 'adjustment', -8000000, '2026-10-25 17:50:00'],
            [$other['business']['id'], 'usage', -999000000, '2026-10-25 17:40:00']] as [$business, $type, $amount, $created]) {
            DB::table('sales_ai_entries')->insert(['business_id' => $business, 'type' => $type, 'amount_micros' => $amount, 'balance_after_micros' => 0,
                'event_key' => 'chart-fixture:'.Str::uuid(), 'description' => 'Usage chart fixture', 'created_at' => $created]);
        }
        $this->withToken($this->owner['access_token'])->withHeader('X-MKPOS-Auth', 'token');
        $result = $this->getJson('/api/shop-admin/sales-ai/wallet?limit=2&offset=2&with_total=1&include_usage=1')->assertOk()
            ->assertJsonCount(2, 'items')->assertJsonPath('total', 5)->assertJsonPath('limit', 2)->assertJsonPath('offset', 2)
            ->assertJsonPath('has_more', true)->assertJsonPath('usage.timezone', 'Asia/Yangon')
            ->assertJsonCount(30, 'usage.daily.items')->assertJsonCount(12, 'usage.monthly.items')->json('usage');
        $this->assertSame('2026-10-26', $result['daily']['items'][29]['key']);
        $this->assertSame('2.000000', $result['daily']['items'][29]['cost']);
        $this->assertSame('1.234567', $result['daily']['items'][28]['cost']);
        $this->assertSame('3.234567', $result['daily']['total']);
        $this->assertSame('3.234567', $result['monthly']['total']);
        $this->getJson('/api/shop-admin/sales-ai/wallet?limit=2&offset=4&with_total=1')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', false)->assertJsonMissingPath('usage');
        $this->getJson('/api/shop-admin/sales-ai/wallet?limit=101')->assertUnprocessable();
        $this->travelBack();
    }

    public function test_bulk_handover_is_tenant_scoped_idempotent_and_does_not_replay_waiting_customer_messages(): void
    {
        $this->enable();
        DB::table('shop_chat_threads')->where('uuid', $this->thread)->update(['sales_ai_mode' => 'human', 'sales_ai_version' => 3]);
        $this->send('Waiting customer message');
        $original = (array) DB::table('shop_chat_threads')->where('uuid', $this->thread)->first();
        unset($original['id']);
        $waiting = (string) Str::uuid();
        DB::table('shop_chat_threads')->insert(array_replace($original, ['uuid' => $waiting, 'platform_customer_id' => null, 'sales_ai_mode' => 'waiting_owner', 'sales_ai_version' => 1]));
        $this->postJson('/api/shop-admin/chat/handoff-all')->assertUnauthorized();
        $other = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', 'token')->postJson('/api/auth/register', ['business_name' => 'Other bulk fixture', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $foreign = (string) Str::uuid();
        DB::table('shop_chat_threads')->insert(array_replace($original, ['uuid' => $foreign, 'business_id' => $other['business']['id']]));
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat/config')->assertOk()->assertJsonPath('sales_ai_enabled', true);
        $this->postJson('/api/shop-admin/chat/handoff-all', ['selected_uuid' => $foreign])->assertNotFound();
        $this->postJson('/api/shop-admin/chat/handoff-all', ['selected_uuid' => $this->thread])->assertOk()->assertJsonPath('updated_count', 2)
            ->assertJsonPath('thread.sales_ai.mode', 'ai')->assertJsonPath('thread.sales_ai.version', 4);
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $waiting, 'sales_ai_mode' => 'ai', 'sales_ai_version' => 2]);
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $foreign, 'sales_ai_mode' => 'human', 'sales_ai_version' => 3]);
        $this->postJson('/api/shop-admin/chat/handoff-all', ['selected_uuid' => $this->thread])->assertOk()->assertJsonPath('updated_count', 0)->assertJsonPath('thread.sales_ai.version', 4);
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->business)->count());
    }

    public function test_bulk_handover_requires_sales_ai_to_be_enabled(): void
    {
        DB::table('shop_chat_threads')->where('uuid', $this->thread)->update(['sales_ai_mode' => 'human']);
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/handoff-all')->assertUnprocessable();
        $this->assertDatabaseHas('shop_chat_threads', ['uuid' => $this->thread, 'sales_ai_mode' => 'human']);
    }

    public function test_handover_does_not_reply_to_human_period_messages_and_only_new_messages_trigger_ai(): void
    {
        $this->enable();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/handoff', ['mode' => 'human', 'version' => 1])->assertOk();
        $this->withToken($this->buyer['access_token']);
        $this->send('Old question handled by the owner');
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->business)->count());
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/handoff', ['mode' => 'ai', 'version' => 2])->assertOk()->assertJsonPath('thread.sales_ai.responding', false);
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->business)->count());
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/handoff', ['mode' => 'ai', 'version' => 3])->assertOk();
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->business)->count());
        $this->withToken($this->buyer['access_token']);
        $old = DB::table('shop_chat_messages')->where('body', 'Old question handled by the owner')->where('thread_id', DB::table('shop_chat_threads')->where('uuid', $this->thread)->value('id'))->first();
        $this->postJson('/api/ecommerce/chat/'.$this->thread.'/messages', ['body' => $old->body, 'client_id' => $old->client_id])->assertOk();
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->business)->count());
        $id = $this->send('A new question after handover');
        $this->assertNotEmpty($id);
        $this->assertSame(1, DB::table('sales_ai_runs')->where('business_id', $this->business)->count());
        Http::assertNothingSent();
    }

    public function test_current_name_personality_policies_and_human_history_are_sent_to_the_provider(): void
    {
        $this->enable(['name' => 'Wyne Sales Helper', 'instructions' => 'Ask for the preferred bag size.', 'soul' => 'Warm and concise.', 'sales_policies' => ['delivery' => 'Delivery within two business days.'], 'tool_permissions' => []]);
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/messages', ['body' => 'I am the human owner, Min Min.', 'client_id' => (string) Str::uuid()])->assertOk();
        $current = DB::table('shop_chat_threads')->where('uuid', $this->thread)->first();
        $this->postJson('/api/shop-admin/chat/'.$this->thread.'/handoff', ['mode' => 'ai', 'version' => (int) $current->sales_ai_version])->assertOk();
        $this->withToken($this->buyer['access_token']);
        Http::fake(['api.deepseek.com/*' => Http::response($this->response())]);
        $id = $this->send('What is your name?');
        app(Runner::class)->process($id);
        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];
            $system = collect($messages)->firstWhere('role', 'system')['content'];
            $this->assertStringContainsString('current public assistant name', $system);
            foreach (['Wyne Sales Helper', 'Ask for the preferred bag size.', 'Warm and concise.', 'Delivery within two business days.'] as $setting) {
                $this->assertStringContainsString($setting, $system);
            }
            $history = collect($messages)->firstWhere('role', 'assistant')['content'];
            $this->assertStringContainsString('Human shop representative', $history);
            $this->assertStringContainsString('Min Min', $history);

            return true;
        });
        $this->assertDatabaseHas('shop_chat_messages', ['sales_ai_run_id' => $id, 'is_ai' => true, 'ai_name' => 'Wyne Sales Helper']);
    }

    private function fakeTopupReceipt(array $changes = []): void
    {
        SystemAgent::fake(fn () => array_replace(['readable' => true, 'payment_status' => 'completed', 'amount' => '1000', 'currency' => 'MMK',
            'recipient_account' => '******9999', 'recipient_name' => 'Platform', 'provider' => 'KBZPay',
            'transaction_reference' => 'OCR-'.Str::uuid(), 'paid_at' => '', 'notes' => ''], $changes));
    }

    private function topupBody(): array
    {
        $this->withToken($this->owner['access_token']);
        $method = DB::table('payment_methods')->insertGetId(['bank' => 'KBZPay', 'account_no' => '099999999', 'account_name' => 'Platform', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return ['request_key' => (string) Str::uuid(), 'amount' => 1000, 'payment_method_id' => $method, 'payment_screenshot' => UploadedFile::fake()->image('receipt.png', 242, 322)];
    }

    public function test_topup_agent_extracts_reference_saves_report_and_retries_without_another_ai_call(): void
    {
        $this->fakeTopupReceipt(['transaction_reference' => '000-TOPUP-123']);
        $body = $this->topupBody();
        $row = $this->post('/api/shop-admin/sales-ai/topups', $body)->assertCreated()
            ->assertJsonPath('request.transaction_reference', '000-TOPUP-123')
            ->assertJsonPath('request.prevalidation.verdict', 'looks_consistent')
            ->assertJsonPath('request.prevalidation.funds_verified', false)->json('request');
        $this->assertDatabaseHas('sales_ai_topups', ['id' => $row['id'], 'status' => 'pending']);
        $checks = DB::table('owner_payment_validations')->count();
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertCreated()->assertJsonPath('request.id', $row['id']);
        $this->assertSame($checks, DB::table('owner_payment_validations')->count());
        $this->assertSame(500_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
        $this->assertSame(0, DB::table('sales_ai_entries')->where('topup_id', $row['id'])->count());
    }

    public function test_topup_agent_blocks_wrong_amount_with_a_short_correction_and_no_request(): void
    {
        $this->fakeTopupReceipt(['amount' => '500']);
        $body = $this->topupBody();
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertUnprocessable()
            ->assertJsonPath('errors.payment_screenshot.0', 'Amount mismatch. Expected 1,000 MMK.');
        $this->assertSame(0, DB::table('sales_ai_topups')->where('business_id', $this->business)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('sales-ai-payment-proofs/'.$this->business));
    }

    public function test_topup_agent_failure_does_not_submit_or_credit_and_a_retry_can_pass(): void
    {
        $body = $this->topupBody();
        SystemAgent::fake(fn () => throw new \RuntimeException('Fixture provider unavailable'));
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertStatus(503);
        $this->assertSame(0, DB::table('sales_ai_topups')->where('business_id', $this->business)->count());
        $this->fakeTopupReceipt();
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertCreated()->assertJsonPath('request.status', 'pending');
    }

    public function test_topup_agent_blocks_an_extracted_subscription_duplicate_without_an_entered_reference(): void
    {
        $body = $this->topupBody();
        $reference = 'DUP-'.Str::uuid();
        DB::table('subscription_requests')->insert(['business_id' => $this->business, 'subscription_plan_id' => DB::table('subscription_plans')->value('id'), 'payment_bank' => 'KBZPay', 'transaction_id' => $reference, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeTopupReceipt(['transaction_reference' => $reference]);
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertUnprocessable()->assertJsonValidationErrors('payment_screenshot');
        $this->assertSame(0, DB::table('sales_ai_topups')->where('business_id', $this->business)->count());
    }

    public function test_topup_rechecks_account_binding_if_office_changes_the_account_during_ai_validation(): void
    {
        $body = $this->topupBody();
        SystemAgent::fake(function () use ($body) {
            DB::table('payment_methods')->where('id', $body['payment_method_id'])->update(['account_no' => '091111111']);

            return ['readable' => true, 'payment_status' => 'completed', 'amount' => '1000', 'currency' => 'MMK', 'recipient_account' => '******9999',
                'recipient_name' => 'Platform', 'provider' => 'KBZPay', 'transaction_reference' => 'OCR-CHANGED', 'paid_at' => '', 'notes' => ''];
        });
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertStatus(409);
        $this->assertSame(0, DB::table('sales_ai_topups')->where('business_id', $this->business)->count());
    }

    public function test_topup_agent_does_not_accept_an_entered_reference_different_from_the_receipt(): void
    {
        $this->fakeTopupReceipt(['transaction_reference' => 'OCR-ID']);
        $body = $this->topupBody();
        $body['transaction_reference'] = 'OTHER-ID';
        $this->post('/api/shop-admin/sales-ai/topups', $body)->assertUnprocessable()->assertJsonValidationErrors('transaction_reference');
        $this->assertSame(0, DB::table('sales_ai_topups')->where('business_id', $this->business)->count());
    }

    public function test_topup_evidence_cannot_be_read_by_another_business_and_cancellation_prevents_approval(): void
    {
        $this->fakeTopupReceipt();
        $this->withToken($this->owner['access_token']);
        $method = DB::table('payment_methods')->insertGetId(['bank' => 'KBZPay', 'account_no' => '099999999', 'account_name' => 'Platform', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $row = $this->post('/api/shop-admin/sales-ai/topups', ['request_key' => (string) Str::uuid(), 'amount' => 1000, 'payment_method_id' => $method, 'payment_screenshot' => UploadedFile::fake()->image('proof.png')])->assertCreated()->json('request');
        $other = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', 'token')->postJson('/api/auth/register', ['business_name' => 'Other fixture', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($other['access_token']);
        $this->getJson('/api/shop-admin/sales-ai/topups/'.$row['id'])->assertNotFound();
        $this->getJson('/api/shop-admin/sales-ai/topups/'.$row['id'].'/screenshot')->assertNotFound();
        $this->postJson('/api/shop-admin/sales-ai/topups/'.$row['id'].'/cancel', ['version' => 1])->assertNotFound();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/sales-ai/topups/'.$row['id'].'/cancel', ['version' => 1])->assertOk()->assertJsonPath('request.status', 'cancelled');
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '');
        $admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');
        $this->postJson('/api/office/sales-ai/topups/'.$row['id'].'/review', ['decision' => 'approved', 'version' => 1, 'transaction_reference' => 'TX123'])->assertStatus(409);
        $this->assertSame(500_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
    }

    public function test_topups_are_pending_until_manual_approval_credit_once_and_reject_duplicate_references(): void
    {
        $this->fakeTopupReceipt();
        $this->withToken($this->owner['access_token']);
        $method = DB::table('payment_methods')->insertGetId(['bank' => 'KBZPay', 'account_no' => '099999999', 'account_name' => 'Platform', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $body = ['request_key' => (string) Str::uuid(), 'amount' => 1000, 'payment_method_id' => $method, 'payment_screenshot' => UploadedFile::fake()->image('proof.png', 240, 320)];
        $row = $this->post('/api/shop-admin/sales-ai/topups', $body)->assertCreated()->assertJsonPath('request.status', 'pending')->json('request');
        $this->getJson('/api/shop-admin/sales-ai/wallet')->assertOk()->assertJsonPath('wallet.balance', '500.000000');
        $this->get('/api/shop-admin/sales-ai/topups/'.$row['id'].'/screenshot')->assertOk();
        $this->withHeader('Authorization', '');
        $admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');
        $url = '/api/office/sales-ai/topups/'.$row['id'].'/review';
        $review = ['decision' => 'approved', 'version' => 1, 'transaction_reference' => 'TX-'.Str::uuid()];
        $this->postJson($url, $review)->assertOk()->assertJsonPath('wallet.balance', '1500.000000');
        $this->postJson($url, $review)->assertOk()->assertJsonPath('wallet.balance', '1500.000000');
        $this->assertSame(1, DB::table('sales_ai_entries')->where('topup_id', $row['id'])->count());
        $this->postJson($url, ['decision' => 'rejected', 'version' => 1, 'admin_note' => 'Incorrect proof'])->assertStatus(409);
        $this->getJson('/api/office/sales-ai/topups?business_id='.$this->business)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.status', 'approved');
        $this->withToken($this->owner['access_token'])->withHeader('X-MKPOS-Auth', 'token');
        $second = $this->post('/api/shop-admin/sales-ai/topups', ['request_key' => (string) Str::uuid(), 'amount' => 1000, 'payment_method_id' => $method, 'payment_screenshot' => UploadedFile::fake()->image('different-proof.png', 241, 321)])->assertCreated()->json('request');
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '');
        $this->actingAs($admin, 'office');
        $this->postJson('/api/office/sales-ai/topups/'.$second['id'].'/review', $review)->assertStatus(422);
        $this->postJson('/api/office/sales-ai/topups/'.$second['id'].'/review', ['decision' => 'rejected', 'version' => 1, 'admin_note' => 'Duplicate payment'])->assertOk()->assertJsonPath('request.review_note', 'Duplicate payment')->assertJsonPath('wallet.balance', '1500.000000');
        Http::assertNothingSent();
    }

    public function test_subscription_transaction_blocks_sales_ai_submission_and_approval_without_crediting_the_wallet(): void
    {
        $this->fakeTopupReceipt();
        $this->withToken($this->owner['access_token']);
        $method = DB::table('payment_methods')->insertGetId(['bank' => 'KBZPay', 'account_no' => '099999999', 'account_name' => 'Platform', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $body = ['request_key' => (string) Str::uuid(), 'amount' => 1000, 'payment_method_id' => $method, 'payment_screenshot' => UploadedFile::fake()->image('first.png', 240, 320)];
        $topup = $this->post('/api/shop-admin/sales-ai/topups', $body)->assertCreated()->json('request.id');
        $reference = 'cross-'.Str::uuid();
        DB::table('subscription_requests')->insert(['business_id' => $this->business, 'subscription_plan_id' => DB::table('subscription_plans')->value('id'), 'payment_bank' => 'KPay', 'transaction_id' => $reference, 'payment_transaction_key' => null, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/api/shop-admin/sales-ai/topups', array_replace($body, ['request_key' => (string) Str::uuid(), 'transaction_reference' => strtoupper($reference), 'payment_screenshot' => UploadedFile::fake()->image('second.png', 241, 321)]))->assertUnprocessable()->assertJsonValidationErrors('transaction_reference');
        $admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'role' => 'super_admin', 'is_active' => true]);
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '')->actingAs($admin, 'office');
        $this->postJson('/api/office/sales-ai/topups/'.$topup.'/review', ['decision' => 'approved', 'version' => 1, 'transaction_reference' => $reference])->assertUnprocessable()->assertJsonValidationErrors('transaction_reference');
        $this->assertDatabaseHas('sales_ai_topups', ['id' => $topup, 'status' => 'pending']);
        $this->assertSame(0, DB::table('sales_ai_entries')->where('topup_id', $topup)->count());
        $this->assertSame(500_000_000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->business)->value('balance_micros'));
    }

    public function test_legacy_sales_ai_transaction_blocks_subscription_approval_before_activation_or_financial_posting(): void
    {
        $reference = 'inverse-'.Str::uuid();
        $method = DB::table('payment_methods')->insertGetId(['bank' => 'WavePay', 'account_no' => '099999999', 'account_name' => 'Platform', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_ai_topups')->insert(['business_id' => $this->business, 'owner_id' => $this->owner['user']['id'], 'request_key' => (string) Str::uuid(), 'amount_micros' => 1000_000_000, 'payment_method_id' => $method, 'payment_account' => json_encode(['bank' => 'Wave Money']), 'screenshot_path' => 'fixture/not-opened.png', 'screenshot_hash' => hash('sha256', $reference), 'transaction_reference' => $reference, 'transaction_key' => null, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $request = DB::table('subscription_requests')->insertGetId(['business_id' => $this->business, 'subscription_plan_id' => DB::table('subscription_plans')->value('id'), 'payment_bank' => 'Wave', 'transaction_id' => $reference, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('business_subscriptions')->where('business_id', $this->business)->count();
        $payments = DB::table('subscription_payments')->count();
        $admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'role' => 'super_admin', 'is_active' => true]);
        $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '')->actingAs($admin, 'office');
        $this->postJson('/api/office/subscription-requests/'.$request.'/approve', ['transaction_id' => strtoupper($reference)])->assertUnprocessable();
        $this->assertDatabaseHas('subscription_requests', ['id' => $request, 'status' => 'pending']);
        $this->assertSame($before, DB::table('business_subscriptions')->where('business_id', $this->business)->count());
        $this->assertSame($payments, DB::table('subscription_payments')->count());
    }
}
