<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShopChatTest extends TestCase
{
    use DatabaseTransactions;
    private array $owner;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop_chat.enabled' => true, 'shop_chat.realtime_enabled' => false]);
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Chat Test Shop', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $settings = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->slug = $settings['slug'];
        $this->putJson('/api/shop-admin/settings', array_replace($settings, ['storefront_enabled' => true]))->assertOk();
        $this->withHeader('Authorization', '');
    }

    private function account(): array
    {
        return $this->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
    }

    private function start(array $account): string
    {
        $this->withToken($account['access_token']);
        return $this->postJson('/api/ecommerce/stores/'.$this->slug.'/chat', ['customer_name' => 'Spoof', 'platform_customer_id' => 1])->assertOk()->assertJsonPath('thread.customer_name', 'Buyer')->json('thread.uuid');
    }

    public function test_customer_inbox_is_paginated_and_scoped_to_the_signed_in_account(): void
    {
        $buyer = $this->account();
        $uuid = $this->start($buyer);
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Hello inbox', 'client_id' => (string) Str::uuid()])->assertOk();
        $this->withToken($buyer['access_token']);
        $this->getJson('/api/ecommerce/chat?unread=1')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/ecommerce/chat?q=nonexistent-shop-search')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/chat?offset=0&limit=1')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.uuid', $uuid)
            ->assertJsonPath('items.0.preview', 'Hello inbox')->assertJsonPath('items.0.unread_count', 1)->assertJsonPath('has_more', false);
        $this->getJson('/api/ecommerce/chat?offset=1&limit=1')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/chat/'.$uuid)->assertOk()->assertJsonPath('thread.slug', $this->slug);
        $other = $this->account();
        $this->withToken($other['access_token']);
        $this->getJson('/api/ecommerce/chat')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/chat/'.$uuid)->assertForbidden();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/ecommerce/chat')->assertUnauthorized();
    }

    public function test_owner_can_open_a_platform_buyers_chat_before_the_customer_starts_it(): void
    {
        $account = $this->account();
        $key = 'account:'.$account['customer']['id'];
        $path = '/api/shop-admin/customers/'.$key.'/chat';
        $this->postJson($path)->assertUnauthorized();
        $this->withToken($this->owner['access_token']);
        // An unrelated platform account is not a customer of this business.
        $this->postJson($path)->assertNotFound();
        $orderId = DB::table('ecommerce_orders')->insertGetId([
            'business_id' => $this->owner['business']['id'], 'platform_customer_id' => $account['customer']['id'],
            'reference' => 'C-'.Str::uuid(), 'origin' => 'storefront', 'customer_name' => 'Checkout name',
            'phone' => '091234', 'fulfillment' => 'pickup', 'payment_method' => '{}', 'currency' => 'Ks',
            'subtotal' => 1000, 'total' => 1000, 'tracking_hash' => hash('sha256', Str::uuid()),
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::uuid()),
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uuid = $this->postJson($path)->assertOk()->json('thread.uuid');
        $this->postJson($path)->assertOk()->assertJsonPath('thread.uuid', $uuid);
        $this->assertSame(1, DB::table('shop_chat_threads')->where('business_id', $this->owner['business']['id'])->count());
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Hello buyer', 'client_id' => (string) Str::uuid()])->assertOk();
        // Customer-initiated chat resolves to the same conversation and receives the reply.
        $this->assertSame($uuid, $this->start($account));
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('items.0.body', 'Hello buyer');
        $this->withToken($this->owner['access_token']);
        config(['shop_chat.enabled' => false]);
        $this->postJson($path)->assertForbidden();
        config(['shop_chat.enabled' => true]);
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['platform_customer_id' => null]);
        $guestKey = 'guest:'.hash('sha256', '091234');
        $this->postJson('/api/shop-admin/customers/'.$guestKey.'/chat')->assertNotFound();
    }

    private function publishedProduct(string $name = 'Chat rice'): int
    {
        $this->withToken($this->owner['access_token']);
        $product = $this->postJson('/api/products', ['name' => $name, 'price' => 1500, 'cost' => 600, 'stock' => 10,
            'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 1500]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        return $product['id'];
    }

    public function test_customer_inbox_channel_is_account_scoped_and_message_and_read_events_reach_it(): void
    {
        $buyer = $this->account(); $uuid = $this->start($buyer);
        $channel = 'private-shop.chat.customer.'.$buyer['customer']['id'];
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('test-secret', 4)]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->getJson('/api/ecommerce/chat/inbox-config')->assertOk()->assertJsonPath('channel', $channel)->assertJsonMissingPath('secret');
        $token = $this->postJson('/api/ecommerce/chat/auth', ['channel_name' => $channel])->assertOk()->json('token');
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
        $this->assertSame((int) $buyer['customer']['id'], $claims['customer_id']);
        $this->assertSame('customer', $claims['role']);
        $this->postJson('/api/ecommerce/chat/auth', ['channel_name' => 'private-shop.chat.customer.'.($buyer['customer']['id'] + 100000)])->assertForbidden();
        $other = $this->account();
        $this->withToken($other['access_token']);
        $this->postJson('/api/ecommerce/chat/auth', ['channel_name' => $channel])->assertForbidden();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/auth', ['channel_name' => $channel])->assertForbidden();
        $id = $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Live inbox', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->withToken($buyer['access_token']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $id])->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => in_array($channel, $request['channels'], true) && $request['data'] === ['thread' => $uuid, 'message_id' => $id]);
        $this->withHeader('Authorization', '');
        $this->getJson('/api/ecommerce/chat/inbox-config')->assertUnauthorized();
    }

    public function test_product_cards_are_durable_idempotent_and_work_in_both_chat_directions(): void
    {
        $productId = $this->publishedProduct();
        $account = $this->account();
        $uuid = $this->start($account);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/products?q=rice')->assertOk()->assertJsonPath('items.0.id', $productId);
        $this->withToken($this->owner['access_token']);
        $data = ['client_id' => (string) Str::uuid(), 'product_id' => $productId];
        $messageId = $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', $data)->assertOk()
            ->assertJsonPath('message.product.name', 'Chat rice')->assertJsonPath('message.product.price', 1500)
            ->assertJsonPath('message.product.store_slug', $this->slug)->json('message.id');
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', $data)->assertOk()->assertJsonPath('message.id', $messageId);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', $data + ['body' => 'Changed'])->assertStatus(409);
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('items.0.last_message.product.id', $productId);
        $this->withToken($account['access_token']);
        $product = $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->json('items.0.product');
        foreach (['cost', 'stock', 'sku', 'barcode', 'business_id'] as $key) $this->assertArrayNotHasKey($key, $product);
        $this->getJson('/api/ecommerce/chat/notifications?after_id=0')->assertOk()->assertJsonPath('items.0.preview', 'Shared product: Chat rice');
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'product_id' => $productId, 'body' => 'Is this available?'])->assertOk()->assertJsonPath('message.product.id', $productId);
        $this->withToken($this->owner['access_token']);
        $this->putJson('/api/shop-admin/publications/'.$productId, ['published' => false, 'unit' => 'base'])->assertOk();
        // Existing sent cards and retried messages remain stable after unpublishing.
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', $data)->assertOk()->assertJsonPath('message.id', $messageId);
        $this->getJson('/api/shop-admin/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('items.0.product.price', 1500);
        $this->getJson('/api/ecommerce/stores/'.$this->slug.'/products/'.$productId)->assertNotFound();
    }

    public function test_product_sharing_rejects_private_foreign_and_blocked_products_or_customers(): void
    {
        $productId = $this->publishedProduct();
        $privateId = $this->publishedProduct('Private rice');
        $foreignId = $this->publishedProduct('Other shop rice');
        $this->putJson('/api/shop-admin/publications/'.$privateId, ['published' => false, 'unit' => 'base'])->assertOk();
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('id', $foreignId)->update(['business_id' => $otherBusiness]);
        $account = $this->account(); $other = $this->account();
        $uuid = $this->start($account);
        foreach ([$privateId, $foreignId, 999999999] as $id) $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'product_id' => $id])->assertUnprocessable();
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/products')->assertOk()->assertJsonCount(1, 'items');
        $this->withToken($other['access_token']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/products')->assertForbidden();
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat/'.$uuid.'/products')->assertOk()->assertJsonCount(1, 'items');
        $this->putJson('/api/shop-admin/customers/account:'.$account['customer']['id'].'/block', ['blocked' => true])->assertOk();
        $this->withToken($account['access_token']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'product_id' => $productId])->assertForbidden();
    }

    public function test_app_presence_works_without_opening_chat_and_expires_when_heartbeats_stop(): void
    {
        $first = $this->account(); $second = $this->account();
        $uuid = $this->start($first);
        $other = $this->start($second);
        $this->withToken($first['access_token']);
        $this->postJson('/api/ecommerce/chat/presence')->assertOk();
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.customer_active', true);
        $this->getJson('/api/shop-admin/chat/'.$other.'/messages')->assertOk()->assertJsonPath('thread.customer_active', false);
        $this->postJson('/api/shop-admin/chat/presence')->assertOk();
        $this->withToken($first['access_token']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.owner_active', true);
        $this->travel(61)->seconds();
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.owner_active', false)->assertJsonPath('thread.customer_active', false);
        $this->travelBack();
    }

    public function test_notification_backlog_exposes_a_bounded_continuation_cursor(): void
    {
        $uuid = $this->start($this->account());
        $threadId = DB::table('shop_chat_threads')->where('uuid', $uuid)->value('id');
        $messages = [];
        for ($i = 0; $i < 51; $i++) {
            $messages[] = ['thread_id' => $threadId, 'sender' => 'customer', 'client_id' => (string) Str::uuid(),
                'body' => 'Backlog '.$i, 'created_at' => now()];
        }
        DB::table('shop_chat_messages')->insert($messages);
        $this->withToken($this->owner['access_token']);
        $first = $this->getJson('/api/shop-admin/chat/notifications?after_id=0')->assertOk()
            ->assertJsonCount(50, 'items')->assertJsonPath('has_more', true)->json();
        $this->getJson('/api/shop-admin/chat/notifications?after_id='.$first['cursor'])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('has_more', false);
    }

    public function test_notification_feed_is_scoped_and_excludes_outgoing_and_read_messages(): void
    {
        $account = $this->account(); $other = $this->account();
        $uuid = $this->start($account);
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat/notifications')->assertOk()->assertJsonPath('cursor', 0);
        $this->withToken($account['access_token']);
        $id = $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Incoming', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->getJson('/api/ecommerce/chat/notifications?after_id=0')->assertOk()->assertJsonCount(0, 'items');
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat/notifications?after_id=0')->assertOk()->assertJsonPath('items.0.preview', 'Incoming')->assertJsonPath('unread_count', 1);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/read', ['message_id' => $id])->assertOk();
        $this->getJson('/api/shop-admin/chat/notifications?after_id=0')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('unread_count', 0);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Shop reply', 'client_id' => (string) Str::uuid()])->assertOk();
        $this->withToken($account['access_token']);
        $this->getJson('/api/ecommerce/chat/notifications?after_id=0')->assertOk()->assertJsonPath('items.0.preview', 'Shop reply')->assertJsonPath('unread_count', 1);
        $this->withToken($other['access_token']);
        $this->getJson('/api/ecommerce/chat/notifications?after_id=0')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('unread_count', 0);
        $this->withToken($this->owner['access_token']);
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_chat_threads')->where('uuid', $uuid)->update(['business_id' => $otherBusiness]);
        $this->getJson('/api/shop-admin/chat/notifications?after_id=0')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_guests_staff_tokens_and_expired_accounts_cannot_use_customer_chat(): void
    {
        $account = $this->account();
        $uuid = $this->start($account);
        foreach (['', $this->owner['access_token'], 'pc_invalid'] as $token) {
            $this->withHeader('Authorization', $token ? 'Bearer '.$token : '');
            $this->withHeader('X-Shop-Chat-Token', str_repeat('a', 64));
            $this->postJson('/api/ecommerce/stores/'.$this->slug.'/chat', ['guest_token' => str_repeat('a', 64)])->assertUnauthorized();
            $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertUnauthorized();
            $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Hello', 'client_id' => (string) Str::uuid()])->assertUnauthorized();
            $this->postJson('/api/ecommerce/chat/auth', ['socket_id' => '1.2', 'channel_name' => 'private-shop.chat.thread.'.$uuid])->assertUnauthorized();
        }
        DB::table('platform_customer_tokens')->where('platform_customer_id', $account['customer']['id'])->update(['expires_at' => now()->subMinute()]);
        $this->withToken($account['access_token']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertUnauthorized();
    }

    public function test_owner_unread_total_counts_messages_across_threads_and_excludes_read_and_outgoing_messages(): void
    {
        $first = $this->account(); $second = $this->account();
        $uuid = $this->start($first);
        $id = $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'First', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Second', 'client_id' => (string) Str::uuid()])->assertOk();
        $otherThread = $this->start($second);
        $this->postJson('/api/ecommerce/chat/'.$otherThread.'/messages', ['body' => 'Third', 'client_id' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/shop-admin/chat/unread')->assertUnauthorized();
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat/unread')->assertOk()->assertJsonPath('unread_count', 3);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Reply', 'client_id' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/shop-admin/chat/unread')->assertOk()->assertJsonPath('unread_count', 3);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/read', ['message_id' => $id])->assertOk();
        $this->getJson('/api/shop-admin/chat/unread')->assertOk()->assertJsonPath('unread_count', 2);
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_chat_threads')->where('uuid', $otherThread)->update(['business_id' => $otherBusiness]);
        $this->getJson('/api/shop-admin/chat/unread')->assertOk()->assertJsonPath('unread_count', 1);
    }

    public function test_unread_inbox_filter_excludes_read_outgoing_and_other_business_conversations(): void
    {
        $first = $this->account(); $second = $this->account();
        $uuid = $this->start($first);
        $id = $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Incoming', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $other = $this->start($second);
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$other.'/messages', ['body' => 'Outgoing only', 'client_id' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/shop-admin/chat?filter=all')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/shop-admin/chat?filter=unread&limit=1')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.uuid', $uuid)->assertJsonPath('unread_threads.0', $uuid)->assertJsonCount(1, 'items');
        $this->getJson('/api/shop-admin/chat?filter=unread&q=NoMatch')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'items');
        $this->getJson('/api/shop-admin/chat?filter=invalid')->assertUnprocessable();
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/read', ['message_id' => $id])->assertOk();
        $this->getJson('/api/shop-admin/chat?filter=unread')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'unread_threads');
        $this->withToken($first['access_token']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'New incoming', 'client_id' => (string) Str::uuid()])->assertOk();
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_chat_threads')->where('uuid', $uuid)->update(['business_id' => $otherBusiness]);
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat?filter=unread')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'items');
    }

    public function test_conversations_are_account_scoped_and_messages_are_durable_and_idempotent(): void
    {
        $first = $this->account(); $second = $this->account();
        $uuid = $this->start($first);
        $this->assertSame($uuid, $this->start($first));
        $data = ['body' => 'Hello shop', 'client_id' => (string) Str::uuid()];
        $id = $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', $data)->assertOk()->json('message.id');
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', $data)->assertOk()->assertJsonPath('message.id', $id);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', array_replace($data, ['body' => 'Changed']))->assertStatus(409);
        $this->withToken($second['access_token']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertForbidden();
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Intruder', 'client_id' => (string) Str::uuid()])->assertForbidden();
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.unread', 1);
        $this->getJson('/api/shop-admin/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('items.0.body', 'Hello shop');
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/read', ['message_id' => $id])->assertOk();
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('items.0.unread', 0);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Hello buyer', 'client_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('message.sender', 'owner');
        $this->withToken($first['access_token']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages?after_id='.$id)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.body', 'Hello buyer');
        $this->assertSame(2, DB::table('shop_chat_messages')->where('thread_id', DB::table('shop_chat_threads')->where('uuid', $uuid)->value('id'))->count());
    }

    public function test_private_channel_authorization_rejects_other_customers_and_businesses(): void
    {
        $first = $this->account(); $second = $this->account(); $uuid = $this->start($first);
        $secret = str_repeat('server-secret', 4);
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => $secret]);
        $data = ['socket_id' => '123.456', 'channel_name' => 'private-shop.chat.thread.'.$uuid];
        $token = $this->postJson('/api/ecommerce/chat/auth', $data)->assertOk()->json('token');
        [$payload, $signature] = explode('.', $token);
        $this->assertSame(hash_hmac('sha256', $payload, $secret), $signature);
        $this->assertSame($data['channel_name'], json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['channel']);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame('customer', $claims['role']);
        $this->assertSame($this->owner['business']['id'], $claims['business_id']);
        $this->withToken($second['access_token']);
        $this->postJson('/api/ecommerce/chat/auth', $data)->assertForbidden();
        $this->withToken($this->owner['access_token']);
        $ownerToken = $this->postJson('/api/shop-admin/chat/auth', $data)->assertOk()->json('token');
        $ownerClaims = json_decode(base64_decode(strtr(explode('.', $ownerToken)[0], '-_', '+/')), true);
        $this->assertSame('owner', $ownerClaims['role']);
        $this->assertSame($this->owner['business']['id'], $ownerClaims['business_id']);
        $this->postJson('/api/shop-admin/chat/auth', ['socket_id' => '123.456', 'channel_name' => 'private-shop.chat.business.'.($this->owner['business']['id'] + 100000)])->assertForbidden();
        $otherId = DB::table('businesses')->insertGetId(['name' => 'Other', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_chat_threads')->where('uuid', $uuid)->update(['business_id' => $otherId]);
        $this->getJson('/api/shop-admin/chat/'.$uuid.'/messages')->assertNotFound();
        $this->postJson('/api/shop-admin/chat/auth', $data)->assertNotFound();
        $this->getJson('/api/ecommerce/chat/config')->assertOk()->assertJsonMissingPath('secret')->assertJsonMissingPath('publish_host');
    }

    public function test_blocked_accounts_cannot_start_or_send_and_invalid_messages_are_rejected(): void
    {
        $account = $this->account(); $uuid = $this->start($account);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => ' ', 'client_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => str_repeat('x', 2001), 'client_id' => (string) Str::uuid()])->assertUnprocessable();
        DB::table('shop_customer_controls')->insert(['business_id' => $this->owner['business']['id'], 'customer_key' => 'account:'.$account['customer']['id'], 'blocked' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Blocked', 'client_id' => (string) Str::uuid()])->assertForbidden();
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk();
        DB::table('shop_chat_threads')->where('uuid', $uuid)->delete();
        $this->postJson('/api/ecommerce/stores/'.$this->slug.'/chat')->assertForbidden();
    }

    public function test_socket_outage_preserves_the_message_and_publish_uses_a_signed_identifier_event(): void
    {
        $uuid = $this->start($this->account());
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => 'test-server-secret', 'shop_chat.publish_host' => 'socket.test', 'shop_chat.publish_port' => 8080, 'shop_chat.publish_scheme' => 'http']);
        Http::fake(['*' => Http::response([], 503)]);
        $id = $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Durable message', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('items.0.body', 'Durable message');
        Http::assertSent(function ($request) use ($uuid, $id) {
            $timestamp = $request->header('X-Chat-Timestamp')[0];
            $this->assertSame(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'test-server-secret'), $request->header('X-Chat-Signature')[0]);
            $this->assertSame(['thread' => $uuid, 'message_id' => $id], json_decode($request->body(), true)['data']);
            return $request->url() === 'http://socket.test:8080/events';
        });
    }

    public function test_message_history_is_bounded_and_older_pages_keep_chronological_order(): void
    {
        $uuid = $this->start($this->account());
        $threadId = DB::table('shop_chat_threads')->where('uuid', $uuid)->value('id');
        $ids = [];
        for ($i = 0; $i < 55; $i++) $ids[] = DB::table('shop_chat_messages')->insertGetId(['thread_id' => $threadId, 'client_id' => (string) Str::uuid(), 'sender' => 'customer', 'body' => 'Message '.$i, 'created_at' => now()]);
        $latest = $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonCount(50, 'items')->assertJsonPath('has_more', true)->json('items');
        $this->assertSame($ids[5], $latest[0]['id']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages?before_id='.$ids[5])->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('has_more', false)->assertJsonPath('items.0.id', $ids[0]);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages?after_id='.$ids[52])->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', $ids[53]);
    }

    public function test_inbox_cursor_continues_past_equal_timestamps_and_new_threads_without_skipping(): void
    {
        $uuid = $this->start($this->account());
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $accountId = DB::table('platform_customers')->insertGetId(['name' => 'Buyer '.$i, 'email' => Str::uuid().'@example.test', 'password' => 'unused', 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = DB::table('shop_chat_threads')->insertGetId(['uuid' => (string) Str::uuid(), 'business_id' => $this->owner['business']['id'], 'platform_customer_id' => $accountId,
                'customer_name' => 'Buyer '.$i, 'created_at' => now(), 'updated_at' => '2026-10-01 10:00:00']);
        }
        DB::table('shop_chat_threads')->where('uuid', $uuid)->update(['updated_at' => '2026-10-01 10:00:00']);
        $this->withToken($this->owner['access_token']);
        $first = $this->getJson('/api/shop-admin/chat?limit=2')->assertOk()->assertJsonCount(2, 'items')->json();
        $this->assertSame('Buyer 4', $first['items'][0]['customer_name']);
        DB::table('shop_chat_threads')->where('id', $ids[4])->update(['updated_at' => now()]);
        $second = $this->getJson('/api/shop-admin/chat?limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->assertJsonCount(2, 'items')->json();
        $this->assertSame(['Buyer 2', 'Buyer 1'], array_column($second['items'], 'customer_name'));
        $last = $this->getJson('/api/shop-admin/chat?limit=2&cursor='.urlencode($second['next_cursor']))->assertOk()->assertJsonCount(2, 'items')->json();
        $this->assertNull($last['next_cursor']);
        $this->assertSame(['Buyer 0', 'Buyer'], array_column($last['items'], 'customer_name'));
        $this->getJson('/api/shop-admin/chat?cursor=invalid')->assertUnprocessable();
    }

    public function test_preview_and_read_receipts_are_scoped_monotonic_and_broadcast_only_on_change(): void
    {
        $buyer = $this->account(); $other = $this->account();
        $uuid = $this->start($buyer);
        $incoming = $this->postJson('/api/ecommerce/chat/'.$uuid.'/messages', ['body' => 'Question', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->withToken($this->owner['access_token']);
        $outgoing = $this->postJson('/api/shop-admin/chat/'.$uuid.'/messages', ['body' => 'Our reply', 'client_id' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('items.0.last_message.body', 'Our reply')->assertJsonPath('items.0.last_message.sender', 'owner')->assertJsonPath('items.0.customer_read_id', 0);
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => 'test-server-secret']);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->withToken($other['access_token']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $outgoing])->assertForbidden();
        $this->withToken($buyer['access_token']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $outgoing + 10000])->assertUnprocessable();
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $outgoing])->assertOk();
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $incoming])->assertOk();
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $outgoing])->assertOk();
        Http::assertSentCount(1);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.customer_read_id', $outgoing)->assertJsonPath('thread.owner_read_id', 0);
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/'.$uuid.'/read', ['message_id' => $incoming])->assertOk();
        Http::assertSentCount(2);
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('items.0.unread', 0)->assertJsonPath('items.0.customer_read_id', $outgoing);
        $this->withHeader('Authorization', '');
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/read', ['message_id' => $outgoing])->assertUnauthorized();
    }

    public function test_last_seen_is_persisted_without_reordering_threads_and_is_account_scoped(): void
    {
        $buyer = $this->account(); $other = $this->account(); $uuid = $this->start($buyer);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.owner_last_seen_at', null)->assertJsonPath('thread.customer_last_seen_at', null);
        DB::table('shop_chat_threads')->where('uuid', $uuid)->update(['updated_at' => '2026-10-01 10:00:00']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/presence')->assertOk();
        $customerSeen = DB::table('shop_chat_threads')->where('uuid', $uuid)->value('customer_last_seen_at');
        $this->assertNotNull($customerSeen);
        $this->assertSame('2026-10-01 10:00:00', DB::table('shop_chat_threads')->where('uuid', $uuid)->value('updated_at'));
        $this->withToken($other['access_token']);
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/presence')->assertForbidden();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/chat/presence')->assertOk();
        $ownerSeen = DB::table('shop_chat_presence')->where('business_id', $this->owner['business']['id'])->value('owner_last_seen_at');
        $this->assertNotNull($ownerSeen);
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('items.0.customer_last_seen_at', \App\Support\AppTime::utc($customerSeen));
        $this->withToken($buyer['access_token']);
        $this->getJson('/api/ecommerce/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.owner_last_seen_at', \App\Support\AppTime::utc($ownerSeen));
        $this->withHeader('Authorization', '');
        $this->postJson('/api/ecommerce/chat/'.$uuid.'/presence')->assertUnauthorized();
    }

    public function test_chat_profile_link_works_before_a_purchase_without_exposing_unrelated_accounts(): void
    {
        $buyer = $this->account(); $other = $this->account(); $uuid = $this->start($buyer);
        $key = 'account:'.$buyer['customer']['id'];
        DB::table('platform_customers')->where('id', $buyer['customer']['id'])->update(['avatar_path' => 'platform-customer-avatars/demo.jpg']);
        $avatar = \App\Services\PlatformCustomerAvatar::url('platform-customer-avatars/demo.jpg');
        $this->withToken($this->owner['access_token']);
        $this->getJson('/api/shop-admin/chat')->assertOk()->assertJsonPath('items.0.customer_key', $key)->assertJsonPath('items.0.avatar_url', $avatar);
        $this->getJson('/api/shop-admin/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('thread.avatar_url', $avatar);
        $this->getJson('/api/shop-admin/customers/'.urlencode($key))->assertOk()->assertJsonPath('customer.avatar_url', $avatar);
        $this->getJson('/api/shop-admin/customers/'.urlencode($key))->assertOk()->assertJsonPath('customer.customer_name', 'Buyer')->assertJsonPath('report.total_orders', 0)->assertJsonCount(0, 'report.currencies');
        $this->getJson('/api/shop-admin/customers/'.urlencode($key).'/history')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/customers')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/customers/'.urlencode('account:'.$other['customer']['id']))->assertNotFound();
        DB::table('shop_chat_threads')->where('uuid', $uuid)->delete();
        $this->getJson('/api/shop-admin/customers/'.urlencode($key))->assertNotFound();
    }

    public function test_images_are_compressed_private_idempotent_and_expire_after_fourteen_days(): void
    {
        Storage::fake('local');
        $buyer = $this->account(); $other = $this->account(); $uuid = $this->start($buyer);
        $canvas = imagecreatetruecolor(1000, 1000);
        for ($y = 0; $y < 1000; $y++) for ($x = 0; $x < 1000; $x++) imagesetpixel($canvas, $x, $y, (($x * 92837111) ^ ($y * 689287499) ^ ($x * $y * 31)) & 0xffffff);
        ob_start(); imagejpeg($canvas, null, 95); $original = ob_get_clean(); imagedestroy($canvas);
        $this->assertGreaterThan(500 * 1024, strlen($original));
        $this->assertLessThanOrEqual(2 * 1024 * 1024, strlen($original));
        $file = UploadedFile::fake()->createWithContent('image.jpg', $original);
        $data = ['body' => 'Image caption', 'client_id' => (string) Str::uuid(), 'image' => $file];
        $id = $this->post('/api/ecommerce/chat/'.$uuid.'/messages', $data)->assertOk()->assertJsonPath('message.image.mime', 'image/webp')->assertJsonPath('message.image.status', 'available')->assertJsonMissingPath('message.image_path')->json('message.id');
        $row = DB::table('shop_chat_messages')->where('id', $id)->first();
        $this->assertLessThanOrEqual(500 * 1024, Storage::disk('local')->size($row->image_path));
        $this->assertSame(14.0, \Carbon\Carbon::parse($row->created_at)->diffInDays($row->image_expires_at));
        $this->post('/api/ecommerce/chat/'.$uuid.'/messages', $data)->assertOk()->assertJsonPath('message.id', $id);
        $this->assertCount(1, Storage::disk('local')->allFiles('shop-chat-images'));
        $this->get('/api/ecommerce/chat/'.$uuid.'/messages/'.$id.'/image')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->withToken($other['access_token']);
        $this->get('/api/ecommerce/chat/'.$uuid.'/messages/'.$id.'/image')->assertForbidden();
        $this->withToken($this->owner['access_token']);
        $this->get('/api/shop-admin/chat/'.$uuid.'/messages/'.$id.'/image')->assertOk();
        $this->travelTo(\Carbon\Carbon::parse($row->image_expires_at));
        try {
            $this->get('/api/shop-admin/chat/'.$uuid.'/messages/'.$id.'/image')->assertStatus(410);
            $this->getJson('/api/shop-admin/chat/'.$uuid.'/messages')->assertOk()->assertJsonPath('items.0.image.status', 'expired')->assertJsonPath('items.0.body', 'Image caption');
            $this->artisan('shop-chat:expire-images')->assertExitCode(0);
            Storage::disk('local')->assertMissing($row->image_path);
            $this->assertNull(DB::table('shop_chat_messages')->where('id', $id)->value('image_path'));
            $this->assertNotNull(DB::table('shop_chat_messages')->where('id', $id)->value('image_deleted_at'));
            $this->assertSame(1, DB::table('shop_chat_messages')->where('thread_id', $row->thread_id)->count());
            $this->withToken($buyer['access_token']);
            $this->post('/api/ecommerce/chat/'.$uuid.'/messages', $data)->assertOk()->assertJsonPath('message.image.status', 'expired');
            $this->assertCount(0, Storage::disk('local')->allFiles('shop-chat-images'));
        } finally { $this->travelBack(); }
    }

    public function test_palette_png_images_are_compressed_to_webp(): void
    {
        Storage::fake('local'); $buyer = $this->account(); $uuid = $this->start($buyer);
        $canvas = imagecreate(40, 40);
        imagecolorallocate($canvas, 20, 180, 90);
        ob_start(); imagepng($canvas); $bytes = ob_get_clean(); imagedestroy($canvas);
        $this->post('/api/ecommerce/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->createWithContent('palette.png', $bytes)])
            ->assertOk()->assertJsonPath('message.image.mime', 'image/webp');
        $files = Storage::disk('local')->allFiles('shop-chat-images');
        $this->assertCount(1, $files);
        $this->assertLessThanOrEqual(500 * 1024, Storage::disk('local')->size($files[0]));
    }

    public function test_image_only_messages_work_and_invalid_or_blocked_uploads_do_not_save_files(): void
    {
        Storage::fake('local'); $buyer = $this->account(); $uuid = $this->start($buyer);
        foreach ([UploadedFile::fake()->image('big.jpg')->size(2049), UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')] as $file) {
            $this->post('/api/ecommerce/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'image' => $file])->assertUnprocessable();
        }
        DB::table('shop_customer_controls')->insert(['business_id' => $this->owner['business']['id'], 'customer_key' => 'account:'.$buyer['customer']['id'], 'blocked' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/api/ecommerce/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->image('photo.png')])->assertForbidden();
        $this->assertCount(0, Storage::disk('local')->allFiles('shop-chat-images'));
        $this->withToken($this->owner['access_token']);
        $this->post('/api/shop-admin/chat/'.$uuid.'/messages', ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->image('photo.png')])->assertOk()->assertJsonPath('message.body', '')->assertJsonPath('message.image.status', 'available');
        $this->assertCount(1, Storage::disk('local')->allFiles('shop-chat-images'));
    }
}
