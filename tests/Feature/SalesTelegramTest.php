<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesRepresentative;
use App\Services\SalesAi\PublicTools;
use App\Services\SalesAi\Runner;
use App\Services\SalesAi\Settings;
use App\Services\SalesTelegram\Checkout;
use App\Services\SalesTelegram\Delivery;
use App\Services\SalesTelegram\Identity;
use App\Services\SalesTelegram\Inbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesTelegramTest extends TestCase
{
    use DatabaseTransactions;

    private array $owner;

    private object $connection;

    private int $sequence = 100;

    private string $token;

    private string $secret;

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['cache.default' => 'array', 'shop_chat.realtime_enabled' => false, 'ai.providers.deepseek.key' => 'fixture-ai-key']);
        URL::forceRootUrl('https://fixture.test');
        URL::forceScheme('https');
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => 'fixture', 'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => 0.3, 'output' => 1.2, 'cached_input' => 0.006]]], 3600);
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true, 'api_key_encrypted' => null, 'usd_to_mmk_rate' => '4000', 'sales_ai_markup_percent' => '5']);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 10_000_000_000]);
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Telegram Shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->slug = $store['slug'];
        $this->putJson('/api/shop-admin/settings', array_replace($store, ['storefront_enabled' => true]))->assertOk();
        $settings = $this->getJson('/api/shop-admin/sales-ai')->assertOk()->json('settings');
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['enabled' => true]))->assertOk();
        DB::table('sales_ai_wallets')->where('business_id', $this->owner['business']['id'])->update(['balance_micros' => 10_000_000_000]);
        $this->token = '990001:'.str_repeat('a', 32);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(match (true) {
            str_ends_with($request->url(), '/getMe') => ['ok' => true, 'result' => ['id' => 990001, 'is_bot' => true, 'username' => 'fixture_shop_bot', 'can_join_groups' => true]],
            str_ends_with($request->url(), '/getWebhookInfo') => ['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]],
            default => ['ok' => true, 'result' => ['message_id' => 44]],
        }));
        $row = $this->getJson('/api/shop-admin/sales-ai/telegram')->assertOk()->json('settings');
        $row = $this->putJson('/api/shop-admin/sales-ai/telegram', ['version' => $row['version'], 'bot_token' => $this->token, 'enabled' => true, 'groups_enabled' => true, 'daily_limit' => 200, 'per_user_limit' => 20])->assertOk()->json('settings');
        $this->postJson('/api/shop-admin/sales-ai/telegram/register', ['version' => $row['version'], 'replace_existing' => false])->assertOk();
        $this->connection = DB::table('sales_telegram_connections')->where('business_id', $this->owner['business']['id'])->first();
        $this->secret = Crypt::decryptString($this->connection->secret_encrypted);
    }

    private function update(string $text, int $sender = 123, int $chat = 123, array $extra = []): array
    {
        return ['update_id' => ++$this->sequence, 'message' => ['message_id' => $this->sequence, 'from' => ['id' => $sender, 'is_bot' => false, 'first_name' => 'Customer', 'language_code' => 'en'],
            'chat' => ['id' => $chat, 'type' => $chat < 0 ? 'supergroup' : 'private', 'title' => 'Fixture group'], 'text' => $text] + $extra];
    }

    private function receive(array $update): void
    {
        $this->postJson('/integrations/telegram/sales-ai/'.$this->connection->uuid, $update, ['X-Telegram-Bot-Api-Secret-Token' => $this->secret])->assertOk();
    }

    private function thread(int $sender = 123, int $chat = 123): object
    {
        $id = DB::table('sales_telegram_conversations')->where('connection_id', $this->connection->id)->where('sender_id', $sender)->where('chat_id', $chat)->value('thread_id');

        return DB::table('shop_chat_threads')->where('id', $id)->firstOrFail();
    }

    private function click(string $data, int $sender = 123, int $chat = 123): void
    {
        $this->receive(['update_id' => ++$this->sequence, 'callback_query' => ['id' => 'callback-'.$this->sequence, 'from' => ['id' => $sender, 'is_bot' => false],
            'message' => ['message_id' => 44, 'from' => ['id' => 990001, 'is_bot' => true], 'chat' => ['id' => $chat, 'type' => $chat < 0 ? 'supergroup' : 'private']], 'data' => $data]]);
    }

    private function button(string $label): string
    {
        $outbox = DB::table('sales_telegram_outbox')->whereNotNull('reply_markup')->orderByDesc('id')->firstOrFail();
        $buttons = collect(json_decode($outbox->reply_markup, true)['inline_keyboard'])->flatten(1);

        return $buttons->firstWhere('text', $label)['callback_data'];
    }

    private function checkoutDraft(): object
    {
        $product = $this->postJson('/api/products', ['name' => 'Telegram rice', 'price' => 1500, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 1500]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        DB::table('storefront_settings')->where('business_id', $this->owner['business']['id'])->update(['accepting_orders' => true,
            'payment_methods' => json_encode([['id' => 'cash', 'name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'provider' => 'manual']])]);
        $this->receive($this->update('I want two bags of rice'));
        $thread = app(Identity::class)->context($this->thread());
        $run = DB::table('sales_ai_runs')->where('thread_id', $thread->id)->firstOrFail();
        DB::table('sales_ai_runs')->where('id', $run->id)->update(['status' => 'processing']);
        $agent = new SalesRepresentative($thread, app(Settings::class)->get($thread->business_id), [], $run->id, $run->trigger_message_id);
        app(PublicTools::class)->handle($agent, 'prepare_checkout', ['items' => [['product_id' => $product['id'], 'quantity' => 2]]]);
        $conversation = DB::table('sales_telegram_conversations')->where('thread_id', $thread->id)->firstOrFail();
        $keyboard = app(Checkout::class)->keyboardFor($conversation);
        $this->click($keyboard['inline_keyboard'][0][0]['callback_data']);
        $this->receive($this->update('Guest Buyer'));
        $this->receive($this->update('09123456789'));
        $this->click($this->button('Pickup'));
        $this->click($this->button('Cash'));
        $this->click($this->button('Skip'));
        $this->click($this->button('Skip'));
        $draft = DB::table('sales_telegram_checkouts')->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('review', $draft->step);
        $this->assertNotNull($draft->quote_encrypted);

        return $draft;
    }

    public function test_guest_checkout_requires_signed_confirmation_and_is_idempotent(): void
    {
        $draft = $this->checkoutDraft();
        $confirm = $this->button('Confirm order');
        $this->receive($this->update('yes please confirm'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $this->click($confirm, 456, 456); // Callback cannot be transferred to another Telegram customer.
        $this->assertNull(DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('order_reference'));
        $this->click($confirm);
        $this->click($confirm);
        $order = DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->sole();
        $this->assertNull($order->platform_customer_id);
        $this->assertSame('Guest Buyer', $order->customer_name);
        $this->assertSame(3000, (int) $order->total);
        $this->assertSame('ordered', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $context = app(Identity::class)->context($this->thread());
        $this->assertCount(1, app(Identity::class)->orders($context, 'customer_orders', [])['items']);
        $this->receive($this->update('my orders', 456, 456));
        $this->assertSame([], app(Identity::class)->orders(app(Identity::class)->context($this->thread(456, 456)), 'customer_orders', [])['items']);
    }

    public function test_changed_price_requires_review_and_a_new_confirmation(): void
    {
        $draft = $this->checkoutDraft();
        $old = $this->button('Confirm order');
        $payload = json_decode(Crypt::decryptString($draft->payload_encrypted), true);
        $id = $payload['items'][0]['product_id'];
        $this->putJson('/api/products/'.$id, ['name' => 'Telegram rice', 'price' => 2000, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 2000]]])->assertOk();
        $this->click($old);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $new = $this->button('Confirm order');
        $this->assertNotSame($old, $new);
        $this->click($old);
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $this->click($new);
        $this->assertSame(4000, (int) DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->value('total'));
    }

    public function test_optional_account_link_is_one_use_and_unlink_rotates_private_history(): void
    {
        $this->receive($this->update('hello before linking'));
        $old = $this->thread()->id;
        $buyer = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '')->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($buyer['access_token']);
        $web = $this->postJson('/api/ecommerce/stores/'.$this->slug.'/chat')->assertOk()->json('thread.uuid');
        $url = $this->postJson('/api/ecommerce/chat/'.$web.'/telegram-link')->assertOk()->json('url');
        $nonce = substr($url, strpos($url, 'link_') + 5);
        $this->receive($this->update('/start link_'.$nonce));
        $this->assertSame(1, DB::table('sales_telegram_links')->where('connection_id', $this->connection->id)->count());
        $this->receive($this->update('my account orders'));
        $context = app(Identity::class)->context($this->thread());
        $this->assertNotSame($old, $context->id);
        $this->assertSame((int) $buyer['customer']['id'], (int) $context->platform_customer_id);
        $this->receive($this->update('/start link_'.$nonce, 456, 456));
        $this->assertSame(1, DB::table('sales_telegram_links')->where('connection_id', $this->connection->id)->count());
        $this->assertFalse(DB::table('shop_chat_messages')->where('thread_id', $context->id)->where('body', 'like', '%'.$nonce.'%')->exists());
        $this->receive($this->update('/unlink'));
        $this->receive($this->update('hello without account'));
        $this->assertNull(app(Identity::class)->context($this->thread())->platform_customer_id);
        $this->assertNotSame($context->id, $this->thread()->id);
    }

    public function test_connection_revocation_suppresses_pending_delivery_and_multipart_waits(): void
    {
        $this->receive($this->update('hello'));
        $update = DB::table('sales_telegram_updates')->where('connection_id', $this->connection->id)->firstOrFail();
        app(Delivery::class)->notice($update->id, str_repeat('a', 1800), ['inline_keyboard' => [[['text' => 'Review', 'callback_data' => 'fixed']]]]);
        $rows = DB::table('sales_telegram_outbox')->where('update_id', $update->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->reply_markup);
        $this->assertSame(5, app(Delivery::class)->process($rows[1]->id));
        DB::table('sales_telegram_connections')->where('id', $this->connection->id)->increment('version');
        app(Delivery::class)->process($rows[0]->id);
        app(Delivery::class)->process($rows[1]->id);
        $this->assertSame(2, DB::table('sales_telegram_outbox')->whereIn('id', $rows->pluck('id'))->where('status', 'suppressed')->count());
        Http::assertSentCount(3); // Registration only; no customer messages sent.
    }

    public function test_checkout_permission_revocation_cancels_confirmation(): void
    {
        $draft = $this->checkoutDraft();
        $confirm = $this->button('Confirm order');
        $settings = app(Settings::class)->get($this->owner['business']['id']);
        $this->putJson('/api/shop-admin/sales-ai', array_replace($settings, ['tool_permissions' => array_values(array_diff($settings['tool_permissions'], ['prepare_checkout']))]))->assertOk();
        $this->click($confirm);
        $this->assertSame('cancelled', DB::table('sales_telegram_checkouts')->where('id', $draft->id)->value('step'));
        $this->assertSame(0, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_linked_order_placed_in_telegram_is_not_readable_after_unlink(): void
    {
        $draft = $this->checkoutDraft();
        $buyer = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', '')->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        // This fixture represents an already linked private sender before checkout started.
        DB::table('sales_telegram_links')->insert(['connection_id' => $this->connection->id, 'bot_id' => $this->connection->bot_id, 'sender_id' => 123, 'platform_customer_id' => $buyer['customer']['id'], 'created_at' => now(), 'updated_at' => now()]);
        $this->click($this->button('Confirm order'));
        $this->assertSame((int) $buyer['customer']['id'], (int) DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->value('platform_customer_id'));
        $this->assertCount(1, app(Identity::class)->orders(app(Identity::class)->context($this->thread()), 'customer_orders', [])['items']);
        $this->receive($this->update('/unlink'));
        $this->receive($this->update('orders without linking'));
        $this->assertSame([], app(Identity::class)->orders(app(Identity::class)->context($this->thread()), 'customer_orders', [])['items']);
    }

    public function test_group_membership_change_invalidates_previous_runs_even_after_rejoin(): void
    {
        $mention = ['entities' => [['type' => 'mention', 'offset' => 0, 'length' => 17]]];
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $group = DB::table('sales_telegram_groups')->where('connection_id', $this->connection->id)->firstOrFail();
        DB::table('sales_telegram_groups')->where('id', $group->id)->update(['status' => 'approved']);
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $thread = $this->thread(123, -10055);
        $run = DB::table('sales_ai_runs')->where('thread_id', $thread->id)->firstOrFail();
        foreach (['left', 'member'] as $status) {
            $this->receive(['update_id' => ++$this->sequence, 'my_chat_member' => ['chat' => ['id' => -10055, 'type' => 'supergroup', 'title' => 'Fixture group'], 'new_chat_member' => ['user' => ['id' => 990001], 'status' => $status]]]);
        }
        $this->assertFalse(app(Inbox::class)->current($thread, $run->trigger_message_id));
    }

    public function test_encrypted_settings_and_authenticated_idempotent_admission(): void
    {
        $this->assertNotSame($this->token, $this->connection->token_encrypted);
        $public = $this->getJson('/api/shop-admin/sales-ai/telegram')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->token, $public);
        $this->assertStringNotContainsString($this->secret, $public);
        $update = $this->update('Hello');
        $this->postJson('/integrations/telegram/sales-ai/'.$this->connection->uuid, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        $this->receive($update);
        $this->receive($update);
        $this->assertSame(1, DB::table('sales_ai_runs')->where('business_id', $this->owner['business']['id'])->count());
        $this->assertNull($this->thread()->platform_customer_id);
    }

    public function test_staff_cannot_manage_the_business_bot(): void
    {
        DB::table('users')->where('id', $this->owner['user']['id'])->update(['role' => 'staff']);
        $this->getJson('/api/shop-admin/sales-ai/telegram')->assertForbidden();
        $this->postJson('/api/shop-admin/sales-ai/telegram/test')->assertForbidden();
    }

    public function test_group_approval_mentions_and_per_member_history_are_required(): void
    {
        $mention = ['entities' => [['type' => 'mention', 'offset' => 0, 'length' => 17]]];
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $this->assertSame(0, DB::table('sales_ai_runs')->where('business_id', $this->owner['business']['id'])->count());
        $group = DB::table('sales_telegram_groups')->where('connection_id', $this->connection->id)->first();
        $this->putJson('/api/shop-admin/sales-ai/telegram/groups/'.$group->id, ['version' => $group->version, 'chat_id' => $group->chat_id, 'title' => $group->title, 'status' => 'approved', 'reply_mentions' => true, 'reply_to_bot' => true, 'daily_limit' => 100, 'per_user_limit' => 20])->assertOk();
        $this->receive($this->update('not addressed', 123, -10055));
        $this->receive($this->update('@fixture_shop_bot hello', 123, -10055, $mention));
        $this->receive($this->update('@fixture_shop_bot hello', 456, -10055, $mention));
        $this->assertNotSame($this->thread(123, -10055)->id, $this->thread(456, -10055)->id);
        $context = app(Identity::class)->context($this->thread(123, -10055));
        $this->assertFalse(Identity::permits($context, 'customer_orders'));
        $this->assertFalse(Identity::permits($context, 'prepare_checkout'));
    }

    public function test_same_sales_worker_charges_shop_credit_and_delivery_is_not_replayed(): void
    {
        $this->receive($this->update('Hello'));
        SalesRepresentative::fake(['Hello from your shop.']);
        $run = DB::table('sales_ai_runs')->where('thread_id', $this->thread()->id)->first();
        app(Runner::class)->process($run->id);
        $this->assertSame('completed', DB::table('sales_ai_runs')->where('id', $run->id)->value('status'));
        $delivery = DB::table('sales_telegram_outbox')->whereNotNull('message_id')->first();
        $this->assertNotNull($delivery);
        app(Delivery::class)->process($delivery->id);
        app(Delivery::class)->process($delivery->id);
        Http::assertSentCount(5); // getMe, webhook info, registration, typing, one reply.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 123 && $r['text'] === 'Hello from your shop.');
        $this->assertSame(1, DB::table('sales_ai_entries')->where('business_id', $this->owner['business']['id'])->where('type', 'usage')->count());
    }

    public function test_cross_business_bot_claim_and_group_update_are_rejected(): void
    {
        $other = $this->withHeader('Authorization', '')->withHeader('X-MKPOS-Auth', 'token')->postJson('/api/auth/register', ['business_name' => 'Other shop', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($other['access_token']);
        $row = $this->getJson('/api/shop-admin/sales-ai/telegram')->assertOk()->json('settings');
        $this->putJson('/api/shop-admin/sales-ai/telegram', ['version' => $row['version'], 'bot_token' => $this->token, 'enabled' => true, 'groups_enabled' => true, 'daily_limit' => 100, 'per_user_limit' => 10])->assertUnprocessable();
        $this->getJson('/api/shop-admin/sales-ai/telegram/groups/999999')->assertNotFound();
    }
}
