<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EcommerceService;
use App\Services\ShopPaymentProofValidation;
use App\Ai\Agents\SystemAgent;
use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use App\Services\AiProviderCredentials;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EcommerceApiTest extends TestCase
{
    use DatabaseTransactions;

    private array $owner;
    private array $settings;
    private array $product;

    private function receiptFields(array $changes = []): array
    {
        return array_replace(['readable' => true, 'amount' => '2000', 'currency' => 'Ks', 'recipient_name' => 'Shop Owner',
            'recipient_account' => '******6789', 'provider' => 'KBZPay', 'transaction_reference' => '000123',
            'payment_status' => 'completed', 'paid_at' => '', 'notes' => ''], $changes);
    }

    public function test_checkout_uses_system_agent_to_read_the_actual_uploaded_screenshot(): void
    {
        Storage::fake('local');
        $this->mock(AiAssistantSettings::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['enabled' => true, 'configured' => true]));
        $this->mock(AiProviderCredentials::class, fn ($mock) => $mock->shouldReceive('activate')->once());
        $this->mock(AiBalanceBook::class, function ($mock) {
            $mock->shouldReceive('funded')->once();
            $mock->shouldReceive('pricing')->once()->with('deepseek-flash')->andReturn([]);
            $mock->shouldReceive('chargeSystem')->once()->andReturn('0');
        });
        SystemAgent::fake([$this->receiptFields()]);
        [$id, $result] = $this->order(['payment_method_id' => 'bank']);
        $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
            'proof' => UploadedFile::fake()->image('receipt.png')])->assertOk()->assertJsonPath('validation.status', 'completed');
        SystemAgent::assertPrompted(fn ($prompt) => count($prompt->attachments) === 1);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'payment_status' => 'proof_submitted']);
    }

    public function test_provider_failure_keeps_checkout_order_and_receipt_pending_manual_review(): void
    {
        Storage::fake('local');
        $this->mock(ShopPaymentProofValidation::class, fn ($mock) => $mock->makePartial()->shouldReceive('extract')->once()->andThrow(new \RuntimeException('Provider unavailable')));
        [$id, $result] = $this->order(['payment_method_id' => 'bank']);
        $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
            'proof' => UploadedFile::fake()->image('receipt.png')])->assertOk()->assertJsonPath('validation.status', 'failed');
        $proof = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
        Storage::disk('local')->assertExists($proof->proof_path);
        $this->assertStringContainsString('checking unavailable', $proof->reason);
        $this->assertNull($proof->reference);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'payment_status' => 'proof_submitted']);
    }

    public function test_checkout_proof_is_checked_and_amount_mismatch_is_saved_for_owner_review(): void
    {
        Storage::fake('local');
        $this->settings['payment_methods'][1] += ['bank' => 'KBZPay', 'account_name' => 'Shop Owner', 'account_no' => '09123456789'];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->mock(ShopPaymentProofValidation::class, function ($mock) {
            $mock->makePartial()->shouldReceive('extract')->once()->andReturn($this->receiptFields(['amount' => '3000']));
        });
        [$id, $result] = $this->order(['payment_method_id' => 'bank']);
        $tracking = ['reference' => $result['reference'], 'credential' => $result['tracking_credential']];
        $this->post('/api/ecommerce/proofs', $tracking + ['proof' => UploadedFile::fake()->image('receipt.png')])
            ->assertOk()->assertJsonPath('validation.verdict', 'accepted_with_amount_mismatch')->assertJsonPath('validation.funds_verified', false);
        $proof = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
        $report = json_decode($proof->ai_validation, true);
        $this->assertSame('000123', $proof->reference);
        $this->assertSame('kbz', $proof->payment_provider);
        $this->assertSame('match', array_column($report['checks'], 'status', 'field')['recipient_account']);
        $this->assertStringContainsString('Amount mismatch', $proof->reason);
        $this->postJson('/api/ecommerce/tracking', $tracking)->assertOk()->assertJsonPath('payment_status', 'proof_submitted')
            ->assertJsonPath('payment_reviews.0.reason', $proof->reason);
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()
            ->assertJsonPath('payments.0.ai_validation.flags.0', 'amount_mismatch');
    }

    public function test_checkout_proof_flags_reused_transaction_id_and_wrong_recipient(): void
    {
        Storage::fake('local');
        $this->settings['payment_methods'][1] += ['bank' => 'KBZPay', 'account_name' => 'Shop Owner', 'account_no' => '09123456789'];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->mock(ShopPaymentProofValidation::class, function ($mock) {
            $mock->makePartial()->shouldReceive('extract')->twice()->andReturn($this->receiptFields(), $this->receiptFields(['recipient_account' => '******0000']));
        });
        foreach ([false, true] as $duplicate) {
            [$id, $result] = $this->order(['payment_method_id' => 'bank']);
            $response = $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
                'proof' => UploadedFile::fake()->image('receipt.png')], ['Accept' => 'application/json']);
            if ($duplicate) $response->assertUnprocessable()->assertJsonValidationErrors('proof');
            else $response->assertOk();
            $proof = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
            $report = json_decode($proof->ai_validation, true);
            $checks = array_column($report['checks'], 'status', 'field');
            $this->assertSame($duplicate ? 'mismatch' : 'match', $checks['duplicate_payment']);
            $this->assertSame($duplicate ? 'mismatch' : 'match', $checks['recipient_account']);
            if ($duplicate) $this->assertStringContainsString('Transaction ID already used', $proof->reason);
            $this->assertSame($duplicate ? 'rejected' : 'pending', $proof->status);
            $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'payment_status' => $duplicate ? 'unpaid' : 'proof_submitted']);
        }
    }

    public function test_shop_without_receiving_methods_skips_account_check_but_checks_amount_and_reference(): void
    {
        Storage::fake('local');
        $this->settings['payment_methods'] = [$this->settings['payment_methods'][0]];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->mock(ShopPaymentProofValidation::class, function ($mock) {
            $mock->makePartial()->shouldReceive('extract')->once()->andReturn($this->receiptFields(['recipient_name' => '', 'recipient_account' => '']));
        });
        [$id, $result] = $this->order(['payment_method_id' => 'cash']);
        $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
            'proof' => UploadedFile::fake()->image('receipt.png')])->assertOk()->assertJsonPath('validation.verdict', 'looks_consistent');
        $proof = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
        $checks = array_column(json_decode($proof->ai_validation, true)['checks'], 'status', 'field');
        $this->assertSame('not_required', $checks['recipient_name']);
        $this->assertSame('not_required', $checks['recipient_account']);
        $this->assertSame('match', $checks['amount']);
        $this->assertSame('match', $checks['duplicate_payment']);
    }

    public function test_same_transaction_id_from_different_banks_is_accepted_and_bank_is_saved_from_receipt(): void
    {
        Storage::fake('local');
        $this->settings['payment_methods'][1] += ['bank' => 'KBZPay', 'account_name' => 'Shop Owner', 'account_no' => '09123456789'];
        $this->settings['payment_methods'][] = ['id' => 'wave', 'name' => 'Wave', 'type' => 'transfer', 'bank' => 'WavePay',
            'account_name' => 'Shop Owner', 'account_no' => '09123456789'];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->mock(ShopPaymentProofValidation::class, function ($mock) {
            $mock->makePartial()->shouldReceive('extract')->twice()->andReturn($this->receiptFields(['provider' => 'KBZ Pay']), $this->receiptFields(['provider' => 'Wave Money']));
        });
        $keys = [];
        foreach (['bank' => 'kbz', 'wave' => 'wave'] as $method => $provider) {
            [$id, $result] = $this->order(['payment_method_id' => $method]);
            $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
                'proof' => UploadedFile::fake()->image('receipt.png')], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('validation.verdict', 'looks_consistent');
            $payment = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
            $this->assertSame('000123', $payment->reference);
            $this->assertSame($provider, $payment->payment_provider);
            $keys[] = $payment->transaction_key;
        }
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_checkout_rejects_kbz_bank_receipt_when_its_id_was_used_with_kbzpay(): void
    {
        Storage::fake('local');
        $this->settings['payment_methods'][1] += ['bank' => 'KBZPay', 'account_name' => 'Shop Owner', 'account_no' => '09123456789'];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->mock(ShopPaymentProofValidation::class, function ($mock) {
            $mock->makePartial()->shouldReceive('extract')->twice()->andReturn($this->receiptFields(), $this->receiptFields(['provider' => 'KBZ Bank']));
        });
        foreach ([false, true] as $reused) {
            [$id, $result] = $this->order(['payment_method_id' => 'bank']);
            $response = $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
                'proof' => UploadedFile::fake()->image('receipt.png')], ['Accept' => 'application/json']);
            if ($reused) $response->assertUnprocessable()->assertJsonValidationErrors('proof');
            else $response->assertOk();
            $proof = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
            $checks = array_column(json_decode($proof->ai_validation, true)['checks'], 'status', 'field');
            $this->assertSame('match', $checks['provider']);
            $this->assertSame($reused ? 'mismatch' : 'match', $checks['duplicate_payment']);
            $this->assertSame('kbz', $proof->payment_provider);
            $this->assertSame($reused ? 'rejected' : 'pending', $proof->status);
        }
    }

    public function test_order_notification_feed_is_incremental_and_tenant_scoped(): void
    {
        $baseline = $this->getJson('/api/shop-admin/order-notifications')->assertOk()->assertJsonCount(0, 'items')->json('cursor');
        [$id, $order] = $this->order();
        $this->getJson('/api/shop-admin/order-notifications?after_id='.$baseline)->assertOk()
            ->assertJsonPath('cursor', $id)->assertJsonPath('items.0.reference', $order['reference'])
            ->assertJsonPath('items.0.customer_name', 'Guest')->assertJsonPath('has_more', false);
        $this->getJson('/api/shop-admin/order-notifications?after_id='.$id)->assertOk()->assertJsonCount(0, 'items');
        $this->postJson('/api/auth/register', ['business_name' => 'Other notification shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/order-notifications?after_id=0')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_order_change_feed_recovers_cross_device_updates_and_is_tenant_scoped(): void
    {
        [$id] = $this->order();
        $baseline = $this->getJson('/api/shop-admin/order-notifications')->assertOk()->assertJsonCount(0, 'changes')->json();
        $this->transition($id, 'accepted')->assertOk();
        $feed = $this->getJson('/api/shop-admin/order-notifications?after_id='.$baseline['cursor'].'&after_event_id='.$baseline['event_cursor'])
            ->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('changes.0.order_id', $id)->assertJsonPath('has_more_changes', false)->json();
        $this->assertGreaterThan($baseline['event_cursor'], $feed['event_cursor']);
        $this->getJson('/api/shop-admin/order-notifications?after_id='.$feed['cursor'].'&after_event_id='.$feed['event_cursor'])
            ->assertOk()->assertJsonCount(0, 'changes');
        $this->getJson('/api/shop-admin/order-notifications?after_event_id=-1')->assertUnprocessable();
        $this->postJson('/api/auth/register', ['business_name' => 'Other sync shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/order-notifications?after_event_id=0')->assertOk()->assertJsonCount(0, 'changes');
    }

    public function test_order_change_feed_pages_a_large_reconnect_backlog_without_repeating_events(): void
    {
        [$id] = $this->order();
        $baseline = $this->getJson('/api/shop-admin/order-notifications')->assertOk()->json();
        for ($i = 0; $i < 55; $i++) DB::table('ecommerce_order_events')->insert([
            'business_id' => $this->owner['business']['id'], 'order_id' => $id, 'event' => 'packing', 'created_at' => now()]);
        $first = $this->getJson('/api/shop-admin/order-notifications?after_id='.$baseline['cursor'].'&after_event_id='.$baseline['event_cursor'])
            ->assertOk()->assertJsonCount(50, 'changes')->assertJsonPath('has_more_changes', true)->json();
        $second = $this->getJson('/api/shop-admin/order-notifications?after_id='.$first['cursor'].'&after_event_id='.$first['event_cursor'])
            ->assertOk()->assertJsonCount(5, 'changes')->assertJsonPath('has_more_changes', false)->json();
        $this->assertGreaterThan($first['event_cursor'], $second['changes'][0]['event_id']);
        $this->getJson('/api/shop-admin/order-notifications?after_id='.$second['cursor'].'&after_event_id='.$second['event_cursor'])
            ->assertOk()->assertJsonCount(0, 'changes');
    }

    public function test_order_attention_count_tracks_unique_actionable_orders_and_staff_permissions(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) $ids[] = $this->order()[0];
        foreach ([1 => ['completed', 'paid'], 2 => ['cancelled', 'refunded'], 3 => ['completed', 'paid'], 4 => ['cancelled', 'refund_pending']] as $index => [$status, $payment]) {
            DB::table('ecommerce_orders')->where('id', $ids[$index])->update(['status' => $status, 'payment_status' => $payment]);
        }
        foreach ([$ids[0], $ids[3]] as $id) DB::table('shop_order_requests')->insert(['business_id' => $this->owner['business']['id'],
            'order_id' => $id, 'type' => 'return', 'status' => 'pending', 'reason' => 'Review return', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/shop-admin/order-notifications')->assertOk()->assertJsonPath('action_required_count', 3)->assertJsonCount(0, 'items');
        $this->getJson('/api/shop-admin/order-notifications?after_id='.$ids[4])->assertOk()->assertJsonPath('action_required_count', 3)->assertJsonCount(0, 'items');
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Attention reviewer',
            'permissions' => json_encode(['ecommerce_orders']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Reviewer', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        foreach (['ecommerce_orders' => 0, 'ecommerce_payments' => 1, 'ecommerce_refunds' => 1, 'ecommerce_process' => 2] as $permission => $expected) {
            DB::table('access_roles')->where('id', $role)->update(['permissions' => json_encode(array_values(array_unique(['ecommerce_orders', $permission])))]);
            $this->getJson('/api/shop-admin/order-notifications')->assertOk()->assertJsonPath('action_required_count', $expected);
        }
        $other = $this->postJson('/api/auth/register', ['business_name' => 'Other attention shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json('user.id');
        $this->actingAs(User::findOrFail($other), 'web');
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/order-notifications')->assertOk()->assertJsonPath('action_required_count', 0);
    }

    public function test_order_socket_authorization_requires_own_shop_and_order_permission_even_when_chat_is_disabled(): void
    {
        config(['shop_chat.enabled' => false, 'shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40)]);
        $channel = 'private-shop.orders.business.'.$this->owner['business']['id'];
        $this->getJson('/api/shop-admin/order-notifications/config')->assertOk()->assertJsonPath('channel', $channel)->assertJsonPath('enabled', true);
        $this->postJson('/api/shop-admin/order-notifications/auth', ['channel_name' => $channel])->assertOk()->assertJsonStructure(['token', 'expires_at']);
        $this->postJson('/api/shop-admin/order-notifications/auth', ['channel_name' => 'private-shop.orders.business.2147483647'])->assertForbidden();
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Restricted staff',
            'email' => Str::uuid().'@example.com', 'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/order-notifications')->assertForbidden();
        $this->postJson('/api/shop-admin/order-notifications/auth', ['channel_name' => $channel])->assertForbidden();
    }

    public function test_order_socket_publication_is_deferred_and_not_repeated_for_checkout_retries(): void
    {
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40)]);
        \Illuminate\Support\Facades\Http::fake();
        DB::beginTransaction();
        [$id, $order, $data] = $this->order();
        $this->postJson($this->path('/checkout'), $data)->assertOk()->assertJsonPath('reference', $order['reference']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
        DB::commit();
        \Illuminate\Support\Facades\Http::assertSentCount(3);
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($id) {
            $body = json_decode($request->body(), true);
            return ($body['event'] ?? null) === 'order.changed' && $body['channels'] === ['private-shop.orders.business.'.$this->owner['business']['id']]
                && $body['data']['order_id'] === $id && $body['data']['event_id'] > 0;
        });
        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($id) {
            $body = json_decode($request->body(), true);
            return $body === ['channels' => ['private-shop.orders.business.'.$this->owner['business']['id']],
                'event' => 'order.submitted', 'data' => ['order_id' => $id]];
        });
    }

    public function test_rolled_back_checkout_does_not_publish_an_order_alert(): void
    {
        config(['shop_chat.realtime_enabled' => true]);
        \Illuminate\Support\Facades\Http::fake();
        DB::beginTransaction();
        [$id] = $this->order();
        \Illuminate\Support\Facades\Http::assertNothingSent();
        DB::rollBack();
        $this->assertFalse(DB::table('ecommerce_orders')->where('id', $id)->exists());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost');
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Online Test Shop', 'owner_name' => 'Owner',
            'email' => 'ecommerce-'.Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->settings = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->settings['storefront_enabled'] = true;
        $this->settings['accepting_orders'] = true;
        $this->settings['marketplace_enabled'] = true;
        $this->settings['delivery_zones'] = [['id' => 'city', 'name' => 'City', 'fee' => 500]];
        $this->settings['payment_methods'][] = ['id' => 'bank', 'name' => 'Bank transfer', 'type' => 'transfer', 'instructions' => 'Transfer to the shop.'];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->product = $this->postJson('/api/products', ['name' => 'Test rice', 'price' => 1000, 'cost' => 600, 'stock' => 10,
            'base_unit' => 'Bag', 'purchase_unit' => 'Box', 'purchase_conversion_factor' => 2,
            'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json();
        $this->publish();
    }

    public function test_settings_exposes_slug_lock_after_first_order(): void
    {
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('slug_locked', false);
        $this->order();
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('slug_locked', true);
        $this->putJson('/api/shop-admin/settings', array_replace($this->settings, ['slug' => 'changed-shop']))->assertStatus(409);
    }

    public function test_global_marketplace_category_links_filter_descendants_without_using_business_categories(): void
    {
        $root = DB::table('marketplace_categories')->where('slug', 'groceries-food')->first();
        $child = DB::table('marketplace_categories')->where('slug', 'rice-grains')->first();
        $path = '/api/ecommerce/marketplace/products?q=Test%20rice&marketplace_category_id=';
        $this->getJson($path.$root->id)->assertOk()->assertJsonCount(0, 'items');
        DB::table('products')->where('id', $this->product['id'])->update(['category' => 'Shop custom category']);
        DB::table('marketplace_product_categories')->insert(['product_id' => $this->product['id'], 'business_id' => $this->owner['business']['id'],
            'category_id' => $child->id, 'version' => 1, 'updated_by_admin_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($path.$root->id)->assertOk()->assertJsonPath('items.0.id', $this->product['id'])->assertJsonPath('items.0.category', 'Shop custom category');
        $this->getJson($path.$child->id)->assertOk()->assertJsonCount(1, 'items');
        DB::table('marketplace_categories')->where('id', $child->id)->update(['is_active' => false]);
        $this->getJson($path.$root->id)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/marketplace/products?q=Test%20rice')->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        DB::table('marketplace_categories')->where('id', $child->id)->update(['is_active' => true]);
        DB::table('storefront_settings')->where('business_id', $this->owner['business']['id'])->update(['marketplace_suspended' => true]);
        $this->getJson($path.$root->id)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($path.'invalid')->assertUnprocessable();
    }

    private function publish(string $unit = 'base'): void
    {
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => true, 'unit' => $unit, 'description' => 'Public description'])->assertOk();
    }

    public function test_single_publication_supports_mobile_edit_links_and_enforces_catalog_permission_and_tenancy(): void
    {
        $this->getJson('/api/shop-admin/publications/'.$this->product['id'])->assertOk()
            ->assertJsonPath('id', $this->product['id'])->assertJsonPath('name', 'Test rice')
            ->assertJsonPath('description', 'Public description')->assertJsonPath('base_price', 1000);
        $this->getJson('/api/shop-admin/publications/2147483647')->assertNotFound();
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Catalog mobile',
            'permissions' => json_encode(['ecommerce_catalog']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Catalog staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/publications/'.$this->product['id'])->assertOk();
        DB::table('access_roles')->where('id', $role)->update(['permissions' => json_encode(['ecommerce_orders'])]);
        $this->getJson('/api/shop-admin/publications/'.$this->product['id'])->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other publication shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/publications/'.$this->product['id'])->assertNotFound();
    }

    private function path(string $suffix): string { return '/api/ecommerce/stores/'.$this->settings['slug'].$suffix; }

    private function input(array $extra = []): array
    {
        return array_replace(['items' => [['product_id' => $this->product['id'], 'quantity' => 2]], 'fulfillment' => 'pickup',
            'payment_method_id' => 'cash', 'customer_name' => 'Guest', 'phone' => '0912345678', 'idempotency_key' => (string) Str::uuid()], $extra);
    }

    private function order(array $extra = []): array
    {
        $data = $this->input($extra);
        $quote = $this->postJson($this->path('/quote'), $data)->assertOk()->json();
        $data['quote_hash'] = $quote['quote_hash'];
        $result = $this->postJson($this->path('/checkout'), $data)->assertOk()->json();
        $id = DB::table('ecommerce_orders')->where('reference', $result['reference'])->value('id');
        return [$id, $result, $data];
    }

    private function transition(int $id, string $state, array $extra = [])
    {
        return $this->postJson('/api/shop-admin/orders/'.$id.'/transition', ['status' => $state] + $extra);
    }

    private function pay(int $id, int $amount = 2000): void
    {
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => $amount, 'reference' => 'Cash received', 'verified' => true])->assertOk();
    }

    public function test_catalog_is_opt_in_and_never_exposes_private_product_fields(): void
    {
        $private = $this->postJson('/api/products', ['name' => 'Private', 'price' => 1000, 'stock' => 5])->assertOk()->json();
        $catalog = $this->getJson($this->path('/products'))->assertOk()->assertJsonCount(1, 'items')->json('items.0');
        foreach (['cost', 'stock', 'sku', 'barcode', 'business_id', 'photo_path', 'prices'] as $key) $this->assertArrayNotHasKey($key, $catalog);
        $this->getJson($this->path('/products/'.$private['id']))->assertNotFound();
        $this->assertNull(app(TenantContext::class)->id());
        DB::table('business_subscriptions')->where('business_id', $this->owner['business']['id'])->update(['ends_at' => now()->subDay()]);
        $this->getJson($this->path('/products'))->assertNotFound();
    }

    public function test_cash_on_delivery_is_the_initial_shop_payment_method(): void
    {
        $this->assertSame('cash', $this->settings['payment_methods'][0]['id']);
        $this->assertSame('Cash on delivery', $this->settings['payment_methods'][0]['name']);
        $this->assertTrue($this->settings['payment_methods'][0]['is_active']);
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('payment_methods.0.name', 'Cash on delivery');
        [$id] = $this->order();
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('payment_status', 'unpaid')
            ->assertJsonPath('payment_method.name', 'Cash on delivery');
    }

    public function test_shop_customers_group_completed_purchases_without_creating_credit_contacts(): void
    {
        $creditCount = DB::table('customers')->where('business_id', $this->owner['business']['id'])->count();
        [$first] = $this->order(['customer_name' => 'First name', 'phone' => '09 1234-5678']);
        [$second] = $this->order(['customer_name' => 'Current name', 'phone' => '0912345678', 'email' => 'buyer@example.com']);
        [$pending] = $this->order(['phone' => '0988888888']);
        [$cancelled] = $this->order(['phone' => '0977777777']);
        DB::table('ecommerce_orders')->whereIn('id', [$first, $second])->update(['status' => 'completed', 'payment_status' => 'paid']);
        DB::table('ecommerce_orders')->where('id', $cancelled)->update(['status' => 'cancelled']);
        $buyers = $this->getJson('/api/shop-admin/customers')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.customer_name', 'Current name')->assertJsonPath('items.0.purchase_count', 2)->assertJsonPath('items.0.account_type', 'guest')->json();
        $key = $buyers['items'][0]['customer_key'];
        $this->getJson('/api/shop-admin/customers/'.$key.'/orders?limit=1')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('items.0.id', $second);
        $this->getJson('/api/shop-admin/customers/'.$key.'/orders?limit=1&offset=1')->assertOk()->assertJsonPath('items.0.id', $first);
        $this->getJson('/api/shop-admin/customers?q=buyer@example.com')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/shop-admin/customers?q=0988888888')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($creditCount, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_shop_customer_accounts_are_distinct_from_guests_and_support_filters_and_pagination(): void
    {
        $accountId = DB::table('platform_customers')->insertGetId(['name' => 'Private global name', 'email' => Str::uuid().'@example.com', 'password' => 'not-exposed', 'created_at' => now(), 'updated_at' => now()]);
        [$first] = $this->order(['customer_name' => 'Zulu']);
        [$second] = $this->order(['customer_name' => 'Zulu latest', 'phone' => '0999999999']);
        [$guest] = $this->order(['customer_name' => 'Alpha']);
        DB::table('ecommerce_orders')->whereIn('id', [$first, $second, $guest])->update(['status' => 'completed', 'payment_status' => 'paid']);
        DB::table('ecommerce_orders')->whereIn('id', [$first, $second])->update(['platform_customer_id' => $accountId]);
        $this->getJson('/api/shop-admin/customers?sort=name&limit=1')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('items.0.customer_name', 'Alpha');
        $this->getJson('/api/shop-admin/customers?sort=name&limit=1&offset=1')->assertOk()->assertJsonPath('items.0.customer_name', 'Zulu latest')->assertJsonPath('items.0.purchase_count', 2);
        $this->getJson('/api/shop-admin/customers?account=guest')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.account_type', 'guest');
        $this->getJson('/api/shop-admin/customers?account=account&sort=purchases')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.account_type', 'account')->assertJsonMissing(['password' => 'not-exposed']);
        $this->getJson('/api/shop-admin/customers?sort=invalid')->assertUnprocessable();
    }

    public function test_shop_customer_history_is_tenant_scoped_and_requires_order_permission(): void
    {
        [$id] = $this->order();
        DB::table('ecommerce_orders')->where('id', $id)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $key = $this->getJson('/api/shop-admin/customers')->assertOk()->json('items.0.customer_key');
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com', 'password' => bcrypt('password123'), 'role' => 'staff']);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/customers')->assertForbidden();
        $this->getJson('/api/shop-admin/customers/'.$key.'/orders')->assertForbidden();
        $this->getJson('/api/shop-admin/customers/'.$key)->assertForbidden();
        $this->getJson('/api/shop-admin/customers/'.$key.'/history')->assertForbidden();
        $this->putJson('/api/shop-admin/customers/'.$key.'/block', ['blocked' => true])->assertForbidden();
        $this->postJson('/api/shop-admin/customers/'.$key.'/credit-contact')->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other shop', 'owner_name' => 'Other owner', 'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/customers')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/customers/'.$key.'/orders')->assertNotFound();
        $this->getJson('/api/shop-admin/customers/'.$key)->assertNotFound();
        $this->getJson('/api/shop-admin/customers/'.$key.'/history')->assertNotFound();
        $this->putJson('/api/shop-admin/customers/'.$key.'/block', ['blocked' => true])->assertNotFound();
        $this->postJson('/api/shop-admin/customers/'.$key.'/credit-contact')->assertNotFound();
    }

    public function test_shop_customer_detail_reports_currencies_separately_and_includes_all_order_history(): void
    {
        [$first] = $this->order(['customer_name' => 'Buyer', 'address' => 'Shop checkout address']);
        [$second] = $this->order(['customer_name' => 'Buyer latest', 'address' => 'Latest checkout address']);
        [$active] = $this->order();
        [$cancelled] = $this->order();
        DB::table('ecommerce_orders')->where('id', $first)->update(['status' => 'completed', 'payment_status' => 'paid', 'total' => 3000, 'created_at' => '2026-10-01 08:00:00']);
        DB::table('ecommerce_orders')->where('id', $second)->update(['status' => 'completed', 'payment_status' => 'paid', 'currency' => 'USD', 'total' => 5, 'created_at' => '2026-10-02 08:00:00']);
        DB::table('ecommerce_orders')->where('id', $active)->update(['status' => 'accepted', 'created_at' => '2026-10-03 08:00:00']);
        DB::table('ecommerce_orders')->where('id', $cancelled)->update(['status' => 'cancelled', 'payment_status' => 'refund_pending', 'total' => 1000, 'created_at' => '2026-10-04 08:00:00']);
        $key = $this->getJson('/api/shop-admin/customers')->assertOk()->json('items.0.customer_key');
        $base = '/api/shop-admin/customers/'.$key;
        $this->getJson($base)->assertOk()->assertJsonPath('customer.customer_name', 'Buyer latest')->assertJsonPath('customer.address', 'Latest checkout address')
            ->assertJsonPath('report.total_orders', 4)->assertJsonPath('report.completed_orders', 2)->assertJsonPath('report.active_orders', 1)->assertJsonPath('report.cancelled_orders', 1)
            ->assertJsonPath('report.first_purchase_at', '2026-10-01 01:30:00')->assertJsonPath('report.last_purchase_at', '2026-10-02 01:30:00')
            ->assertJsonPath('report.currencies.0.currency', 'Ks')->assertJsonPath('report.currencies.0.completed_spend', 3000)->assertJsonPath('report.currencies.0.average_purchase', 3000)
            ->assertJsonPath('report.currencies.0.refund_pending', 1000)->assertJsonPath('report.currencies.1.currency', 'USD')->assertJsonPath('report.currencies.1.completed_spend', 5);
        $this->getJson($base.'/history?limit=2')->assertOk()->assertJsonPath('total', 4)->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', $cancelled);
        $this->getJson($base.'/history?status=accepted')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $active);
        $reference = DB::table('ecommerce_orders')->where('id', $second)->value('reference');
        $this->getJson($base.'/history?q='.$reference)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $second);
        $this->getJson($base.'/history?status=invalid')->assertUnprocessable();
        $this->getJson($base.'/history?date_from=2026-10-02&date_to=2026-10-02')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $second);
        $this->getJson($base.'/history?date_from=2026-10-03')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($base.'/history?date_to=2026-10-02')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($base.'/history?date_from=2026-10-03&date_to=2026-10-02')->assertUnprocessable();
        $this->getJson($base.'/history?date_from=invalid')->assertUnprocessable();
        DB::table('businesses')->where('id', $this->owner['business']['id'])->update(['timezone' => 'Asia/Rangoon']);
        DB::table('ecommerce_orders')->where('id', $active)->update(['created_at' => '2026-10-03 00:00:00']);
        $this->getJson($base.'/history?date_from=2026-10-03&date_to=2026-10-03')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $active);
        DB::table('ecommerce_orders')->where('id', $active)->update(['created_at' => '2026-10-04 00:00:00']);
        $this->getJson($base.'/history?date_from=2026-10-03&date_to=2026-10-03')->assertOk()->assertJsonPath('total', 0);
        DB::table('ecommerce_orders')->where('id', $cancelled)->update(['payment_status' => 'refunded']);
        $this->getJson($base)->assertOk()->assertJsonPath('report.currencies.0.refunded', 1000)->assertJsonPath('report.currencies.0.refund_pending', 0);
    }

    public function test_order_customer_link_opens_detail_before_the_first_completed_purchase(): void
    {
        [$id] = $this->order();
        $key = $this->getJson('/api/shop-admin/orders')->assertOk()->json('items.0.customer_key');
        $this->assertSame('guest:'.hash('sha256', '0912345678'), $key);
        $this->getJson('/api/shop-admin/customers/'.$key)->assertOk()->assertJsonPath('customer.customer_name', 'Guest')
            ->assertJsonPath('report.total_orders', 1)->assertJsonPath('report.completed_orders', 0)->assertJsonPath('report.first_purchase_at', null);
        $this->getJson('/api/shop-admin/customers/'.$key.'/history')->assertOk()->assertJsonPath('items.0.id', $id);
        $this->getJson('/api/shop-admin/customers')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('customer_key', $key);
    }

    public function test_shop_customer_block_prevents_new_checkout_and_can_be_removed(): void
    {
        [$id, , $original] = $this->order();
        DB::table('ecommerce_orders')->where('id', $id)->update(['status' => 'completed']);
        $key = $this->getJson('/api/shop-admin/customers')->json('items.0.customer_key');
        $base = '/api/shop-admin/customers/'.$key;
        $this->putJson($base.'/block', ['blocked' => true])->assertOk()->assertJsonPath('customer.blocked', true);
        $this->getJson($base)->assertOk()->assertJsonPath('customer.blocked', true);
        $this->getJson('/api/shop-admin/customers')->assertOk()->assertJsonPath('items.0.blocked', true);
        $this->getJson('/api/shop-admin/customers?block_status=blocked&account=guest&q=Guest&limit=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.blocked', true);
        $this->getJson('/api/shop-admin/customers?block_status=not_blocked')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/customers?block_status=blocked&offset=1&limit=1')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(0, 'items');
        $this->getJson('/api/shop-admin/customers?block_status=invalid')->assertUnprocessable();
        $data = $this->input(['phone' => '+09 123-45678']);
        $data['quote_hash'] = $this->postJson($this->path('/quote'), $data)->assertOk()->json('quote_hash');
        $before = DB::table('ecommerce_orders')->count();
        $this->postJson($this->path('/checkout'), $data)->assertForbidden();
        $this->assertSame($before, DB::table('ecommerce_orders')->count());
        $this->postJson($this->path('/checkout'), $original)->assertOk();
        $this->getJson($base.'/history')->assertOk()->assertJsonPath('total', 1);
        $this->putJson($base.'/block', ['blocked' => false])->assertOk()->assertJsonPath('customer.blocked', false);
        $this->getJson('/api/shop-admin/customers')->assertOk()->assertJsonPath('items.0.blocked', false);
        $this->getJson('/api/shop-admin/customers?block_status=not_blocked')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.blocked', false);
        $this->getJson('/api/shop-admin/customers?block_status=blocked')->assertOk()->assertJsonPath('total', 0);
        $this->postJson($this->path('/checkout'), $data)->assertOk();
        $this->putJson('/api/shop-admin/customers/guest:'.str_repeat('a', 64).'/block', ['blocked' => true])->assertNotFound();
    }

    public function test_shop_customer_credit_contact_is_explicit_idempotent_and_reuses_existing_phone(): void
    {
        [$id] = $this->order(['customer_name' => 'Online buyer', 'address' => 'Yangon']);
        DB::table('ecommerce_orders')->where('id', $id)->update(['status' => 'completed']);
        $key = $this->getJson('/api/shop-admin/customers')->json('items.0.customer_key');
        $base = '/api/shop-admin/customers/'.$key;
        $this->assertSame(0, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
        $result = $this->postJson($base.'/credit-contact')->assertOk()->json('customer.credit_customer_id');
        $this->postJson($base.'/credit-contact')->assertOk()->assertJsonPath('customer.credit_customer_id', $result);
        $this->assertSame(1, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
        $this->getJson('/api/customers/'.$result.'/detail')->assertOk()->assertJsonPath('customer.name', 'Online buyer')->assertJsonPath('summary.balance', 0);
        $this->assertNull(DB::table('ecommerce_orders')->where('id', $id)->value('sale_id'));
        DB::table('shop_customer_controls')->where('customer_key', $key)->delete();
        DB::table('customers')->where('id', $result)->update(['phone' => '+09 123-45678', 'name' => 'Existing POS name']);
        $this->postJson($base.'/credit-contact')->assertOk()->assertJsonPath('customer.credit_customer_id', $result);
        $this->assertSame('Existing POS name', DB::table('customers')->where('id', $result)->value('name'));
        $this->assertSame(1, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_buyer_reference_spending_and_optional_block_reason(): void
    {
        [$first] = $this->order();
        [$second] = $this->order();
        [$pending] = $this->order();
        DB::table('ecommerce_orders')->where('id', $first)->update(['status' => 'completed', 'currency' => 'Ks', 'total' => 1200]);
        DB::table('ecommerce_orders')->where('id', $second)->update(['status' => 'completed', 'currency' => 'USD', 'total' => 42]);
        DB::table('ecommerce_orders')->where('id', $pending)->update(['currency' => 'Ks', 'total' => 9999]);
        $rows = $this->getJson('/api/shop-admin/customers')->assertOk()->json('items');
        $this->assertCount(1, $rows);
        $this->assertSame([['currency' => 'Ks', 'purchase_count' => 1, 'completed_spend' => 1200], ['currency' => 'USD', 'purchase_count' => 1, 'completed_spend' => 42]], $rows[0]['spending']);
        $base = '/api/shop-admin/customers/'.$rows[0]['customer_key'];
        $this->putJson($base.'/block', ['blocked' => true, 'reason' => str_repeat('x', 201)])->assertUnprocessable();
        $this->putJson($base.'/block', ['blocked' => true, 'reason' => '  Repeated fraud  '])->assertOk()->assertJsonPath('customer.block_reason', 'Repeated fraud');
        $this->getJson($base)->assertOk()->assertJsonPath('customer.block_reason', 'Repeated fraud');
        $this->putJson($base.'/block', ['blocked' => false])->assertOk()->assertJsonPath('customer.block_reason', null);
    }

    public function test_order_sorting_is_validated_and_paginates_in_both_directions(): void
    {
        [$first] = $this->order();
        [$second] = $this->order();
        $this->getJson('/api/shop-admin/orders?limit=1')->assertOk()->assertJsonPath('items.0.id', $second);
        $this->getJson('/api/shop-admin/orders?sort=newest&limit=1&offset=1')->assertOk()->assertJsonPath('items.0.id', $first);
        $this->getJson('/api/shop-admin/orders?sort=oldest&limit=1')->assertOk()->assertJsonPath('items.0.id', $first);
        $this->getJson('/api/shop-admin/orders?sort=oldest&limit=1&offset=1')->assertOk()->assertJsonPath('items.0.id', $second);
        $this->getJson('/api/shop-admin/orders?sort=invalid')->assertUnprocessable();
    }

    public function test_buyer_history_and_order_list_share_scoped_item_previews(): void
    {
        [$id] = $this->order();
        DB::table('ecommerce_orders')->where('id', $id)->update(['status' => 'completed']);
        $key = $this->getJson('/api/shop-admin/customers')->assertOk()->json('items.0.customer_key');
        $history = $this->getJson('/api/shop-admin/customers/'.$key.'/history')->assertOk()->assertJsonPath('items.0.item_count', 1)->json('items.0');
        $list = $this->getJson('/api/shop-admin/orders')->assertOk()->assertJsonPath('items.0.item_count', 1)->json('items.0');
        $this->assertSame($list['item_previews'], $history['item_previews']);
        $this->assertSame($this->product['name'], $history['item_previews'][0]['name']);
        $this->getJson('/api/shop-admin/orders/'.$id.'/items/99999999/photo')->assertNotFound();
        Storage::fake('local');
        $path = 'product-photos/test.png';
        Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aY1kAAAAASUVORK5CYII='));
        DB::table('products')->where('id', $this->product['id'])->update(['photo_path' => $path]);
        $url = $this->getJson('/api/shop-admin/customers/'.$key.'/history')->assertOk()->json('items.0.item_previews.0.photo_url');
        $this->get('/api'.$url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('items.0.photo_url', $url);
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other tenant', 'slug' => 'order-card-'.Str::uuid(), 'currency' => 'Ks', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ecommerce_orders')->where('id', $id)->update(['business_id' => $otherBusiness]);
        $this->get('/api'.$url)->assertNotFound();
    }

    public function test_fulfillment_details_are_saved_and_snapshotted_for_pickup_without_changing_business_profile(): void
    {
        $before = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $settings = array_replace($before, ['pickup_address' => '24 Market Street', 'pickup_hours' => '09:00 - 18:00', 'pickup_instructions' => 'We confirm orders before packing.']);
        $this->putJson('/api/shop-admin/settings', $settings)->assertOk()->assertJsonPath('pickup_address', '24 Market Street')->assertJsonPath('pickup_hours', '09:00 - 18:00')->assertJsonPath('address', $before['address']);
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('pickup_address', '24 Market Street')->assertJsonPath('pickup_hours', '09:00 - 18:00');
        [$id] = $this->order();
        $snapshot = "24 Market Street\n09:00 - 18:00\nWe confirm orders before packing.";
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('fulfillment_instructions', $snapshot);
        $this->putJson('/api/shop-admin/settings', array_replace($settings, ['pickup_hours' => '10:00 - 17:00']))->assertOk();
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('fulfillment_instructions', $snapshot);
        $this->putJson('/api/shop-admin/settings', array_replace($settings, ['pickup_address' => str_repeat('x', 256)]))->assertUnprocessable();
        $this->putJson('/api/shop-admin/settings', array_replace($settings, ['pickup_hours' => str_repeat('x', 256)]))->assertUnprocessable();
        $this->putJson('/api/shop-admin/settings', array_replace($settings, ['pickup_instructions' => str_repeat('x', 2001)]))->assertUnprocessable();
        $this->putJson('/api/shop-admin/settings', array_replace($settings, ['pickup_address' => null, 'pickup_hours' => null]))->assertOk();
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('pickup_address', $before['address'])->assertJsonPath('pickup_hours', null);
    }

    public function test_buyer_explicit_credit_choices_are_tenant_scoped_and_do_not_create_balances(): void
    {
        [$order] = $this->order();
        DB::table('ecommerce_orders')->where('id', $order)->update(['status' => 'completed']);
        $key = $this->getJson('/api/shop-admin/customers')->assertOk()->json('items.0.customer_key');
        $base = '/api/shop-admin/customers/'.$key;
        $matching = $this->postJson('/api/customers', ['name' => 'Suggested', 'phone' => '+09 123-45678'])->assertOk()->json('id');
        $selected = $this->postJson('/api/customers', ['name' => 'Chosen contact', 'phone' => '09999999'])->assertOk()->json('id');
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other tenant', 'slug' => 'buyer-test-'.Str::uuid(), 'currency' => 'Ks', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('customers')->insertGetId(['business_id' => $otherBusiness, 'name' => 'Private contact', 'phone' => '0912345678', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($base.'/credit-options')->assertOk()->assertJsonCount(1, 'matches')->assertJsonPath('matches.0.id', $matching);
        $this->postJson($base.'/credit-contact', ['contact_id' => $foreign])->assertNotFound();
        $this->postJson($base.'/credit-contact', ['contact_id' => $selected, 'create_new' => true])->assertUnprocessable();
        $this->postJson($base.'/credit-contact', ['contact_id' => $selected])->assertOk()->assertJsonPath('customer.credit_customer_id', $selected);
        $this->postJson($base.'/credit-contact', ['contact_id' => $selected])->assertOk()->assertJsonPath('customer.credit_customer_id', $selected);
        $this->postJson($base.'/credit-contact', ['contact_id' => $matching])->assertStatus(409);
        $this->getJson('/api/customers/'.$selected.'/detail')->assertOk()->assertJsonPath('summary.balance', 0)->assertJsonPath('customer.name', 'Chosen contact');
        DB::table('shop_customer_controls')->where('business_id', $this->owner['business']['id'])->where('customer_key', $key)->delete();
        $before = DB::table('customers')->where('business_id', $this->owner['business']['id'])->count();
        $created = $this->postJson($base.'/credit-contact', ['create_new' => true])->assertOk()->json('customer.credit_customer_id');
        $this->assertNotSame($matching, $created);
        $this->postJson($base.'/credit-contact', ['create_new' => true])->assertOk()->assertJsonPath('customer.credit_customer_id', $created);
        $this->assertSame($before + 1, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
        $this->getJson('/api/customers/'.$created.'/detail')->assertOk()->assertJsonPath('summary.balance', 0);
    }

    public function test_pos_customer_email_is_optional_validated_searchable_and_editable(): void
    {
        $without = $this->postJson('/api/customers', ['name' => 'No email'])->assertOk()->json('id');
        $this->assertNull(DB::table('customers')->where('id', $without)->value('email'));
        $id = $this->postJson('/api/customers', ['name' => 'Email contact', 'email' => 'Buyer@Example.com'])->assertOk()->assertJsonPath('email', 'buyer@example.com')->json('id');
        $this->getJson('/api/customers?q=buyer@example.com&with_total=true&limit=25')->assertOk()->assertJsonPath('items.0.id', $id);
        $this->putJson('/api/customers/'.$id, ['name' => 'Updated'])->assertOk()->assertJsonPath('email', 'buyer@example.com');
        $this->putJson('/api/customers/'.$id, ['name' => 'Updated', 'email' => 'new@example.com'])->assertOk()->assertJsonPath('email', 'new@example.com');
        $this->putJson('/api/customers/'.$id, ['name' => 'Updated', 'email' => ''])->assertOk()->assertJsonPath('email', null);
        $this->postJson('/api/customers', ['name' => 'Invalid', 'email' => 'bad-email'])->assertUnprocessable();
    }

    public function test_shop_credit_contact_matches_email_and_rejects_conflicting_phone_matches(): void
    {
        [$id] = $this->order(['email' => 'BUYER@example.com']);
        DB::table('ecommerce_orders')->where('id', $id)->update(['status' => 'completed']);
        $key = $this->getJson('/api/shop-admin/customers')->json('items.0.customer_key');
        $emailContact = $this->postJson('/api/customers', ['name' => 'Existing email contact', 'email' => 'buyer@example.com', 'phone' => '099999'])->assertOk()->json('id');
        $this->postJson('/api/shop-admin/customers/'.$key.'/credit-contact')->assertOk()->assertJsonPath('customer.credit_customer_id', $emailContact);
        $this->assertSame(1, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
        DB::table('shop_customer_controls')->where('customer_key', $key)->delete();
        $this->postJson('/api/customers', ['name' => 'Different phone contact', 'phone' => '+09 123-45678'])->assertOk();
        $this->postJson('/api/shop-admin/customers/'.$key.'/credit-contact')->assertStatus(409);
        $this->assertSame(2, DB::table('customers')->where('business_id', $this->owner['business']['id'])->count());
        $this->assertFalse(DB::table('shop_customer_controls')->where('customer_key', $key)->exists());
    }

    public function test_publication_table_shows_current_stock_and_configured_online_prices(): void
    {
        $this->getJson('/api/shop-admin/publications')->assertOk()->assertJsonPath('items.0.stock', 10)
            ->assertJsonPath('items.0.base_price', 1000)->assertJsonPath('items.0.purchase_price', 2000)
            ->assertJsonPath('items.0.currency', 'Ks')->assertJsonPath('items.0.price_type', 'Retail');
        $this->order();
        // Stock remains on hand while an order reserves it.
        $this->getJson('/api/shop-admin/publications')->assertJsonPath('items.0.stock', 10);
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 7.125]);
        DB::table('product_prices')->where('product_id', $this->product['id'])->where('name', 'Retail')->update(['price' => 1500]);
        $this->getJson('/api/shop-admin/publications')->assertJsonPath('items.0.stock', 7.125)
            ->assertJsonPath('items.0.base_price', 1500)->assertJsonPath('items.0.purchase_price', 3000);
        DB::table('product_prices')->insert(['business_id' => $this->owner['business']['id'], 'product_id' => $this->product['id'], 'name' => 'Wholesale', 'price' => 1200]);
        DB::table('storefront_settings')->where('business_id', $this->owner['business']['id'])->update(['price_type' => 'Wholesale']);
        $this->getJson('/api/shop-admin/publications')->assertJsonPath('items.0.price_type', 'Wholesale')
            ->assertJsonPath('items.0.base_price', 1200)->assertJsonPath('items.0.purchase_price', 2400);
        DB::table('product_prices')->where('product_id', $this->product['id'])->where('name', 'Wholesale')->delete();
        $this->getJson('/api/shop-admin/publications')->assertJsonPath('items.0.base_price', null)->assertJsonPath('items.0.purchase_price', null);
    }

    public function test_check_all_publishes_the_entire_filtered_set_and_preserves_other_settings(): void
    {
        $ids = [];
        for ($i = 0; $i < 35; $i++) {
            $ids[] = $this->postJson('/api/products', ['name' => 'Bulk item '.$i, 'category' => 'Bulk', 'price' => 1000, 'stock' => 10,
                'base_unit' => 'Pack', 'purchase_unit' => 'Box', 'purchase_conversion_factor' => 2,
                'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json('id');
        }
        $this->putJson('/api/shop-admin/publications/'.$ids[0], ['published' => false, 'unit' => 'purchase', 'description' => 'Keep me'])->assertOk();
        $filter = '?q=Bulk&category=Bulk&stock_status=in_stock&price_status=priced';
        $this->getJson('/api/shop-admin/publications'.$filter.'&limit=1')->assertJsonPath('total', 35)->assertJsonPath('published_count', 0);
        $this->putJson('/api/shop-admin/publications/bulk'.$filter.'&publication_status=unpublished&limit=1&offset=20', ['published' => true])
            ->assertOk()->assertJsonPath('matched', 35)->assertJsonPath('updated', 35)->assertJsonCount(35, 'items');
        $this->assertSame(35, DB::table('ecommerce_product_settings')->whereIn('product_id', $ids)->where('published', true)->count());
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $ids[0], 'unit' => 'purchase', 'description' => 'Keep me']);
        $this->getJson('/api/shop-admin/publications'.$filter.'&limit=1')->assertJsonPath('published_count', 35);
        $this->putJson('/api/shop-admin/publications/bulk'.$filter.'&publication_status=published', ['published' => false])->assertOk()->assertJsonPath('updated', 35);
        $this->assertSame(0, DB::table('ecommerce_product_settings')->whereIn('product_id', $ids)->where('published', true)->count());
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $this->product['id'], 'published' => true]);
    }

    public function test_selected_publication_updates_only_explicit_ids_and_validates_atomically(): void
    {
        $valid = $this->postJson('/api/products', ['name' => 'Selected valid', 'price' => 1000,
            'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json('id');
        $invalid = $this->postJson('/api/products', ['name' => 'Selected invalid', 'price' => 0])->assertOk()->json('id');
        $path = '/api/shop-admin/publications/bulk';
        $this->putJson($path, ['published' => true, 'product_ids' => [$valid]])->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $this->product['id'], 'published' => true]);
        $this->putJson($path, ['published' => false, 'product_ids' => [$valid]])->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $this->product['id'], 'published' => true]);
        $this->putJson($path, ['published' => true, 'product_ids' => [$valid, $invalid]])->assertUnprocessable();
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $valid, 'published' => false]);
        foreach ([[], [$valid, $valid], [$valid, 2147483647]] as $ids) {
            $this->putJson($path, ['published' => true, 'product_ids' => $ids])->assertUnprocessable();
        }
        $this->postJson('/api/auth/register', ['business_name' => 'Other selected', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->putJson($path, ['published' => false, 'product_ids' => [$this->product['id']]])->assertUnprocessable();
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $this->product['id'], 'published' => true]);
    }

    public function test_bulk_publication_validates_atomically_and_is_tenant_scoped(): void
    {
        $valid = $this->postJson('/api/products', ['name' => 'Valid bulk', 'category' => 'Validation', 'price' => 1000,
            'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json('id');
        $invalid = $this->postJson('/api/products', ['name' => 'Invalid bulk', 'category' => 'Validation', 'price' => 0])->assertOk()->json('id');
        $this->putJson('/api/shop-admin/publications/bulk?category=Validation', ['published' => true])->assertUnprocessable();
        $this->assertSame(0, DB::table('ecommerce_product_settings')->whereIn('product_id', [$valid, $invalid])->where('published', true)->count());
        $this->putJson('/api/shop-admin/publications/bulk?category=Validation&price_status=priced', ['published' => true])->assertOk()->assertJsonPath('updated', 1);
        $this->putJson('/api/shop-admin/publications/bulk?sort=invalid', ['published' => false])->assertUnprocessable();
        $this->postJson('/api/auth/register', ['business_name' => 'Other bulk', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->putJson('/api/shop-admin/publications/bulk?category=Validation', ['published' => false])->assertOk()->assertJsonPath('matched', 0);
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $valid, 'published' => true]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($staff, 'web');
        $this->putJson('/api/shop-admin/publications/bulk', ['published' => false])->assertForbidden();
    }

    public function test_publication_filters_search_sorting_and_pagination_work_together(): void
    {
        $alpha = $this->postJson('/api/products', ['name' => 'Alpha', 'sku' => 'ALPHA-SKU', 'barcode' => '123456789', 'category' => 'Drinks',
            'price' => 3000, 'stock' => 20, 'low_stock_threshold' => 2, 'prices' => [['name' => 'Retail', 'price' => 3000]]])->assertOk()->json();
        $beta = $this->postJson('/api/products', ['name' => 'Beta', 'category' => 'Drinks', 'price' => 500, 'stock' => 4, 'low_stock_threshold' => 5,
            'prices' => [['name' => 'Retail', 'price' => 500]]])->assertOk()->json();
        $empty = $this->postJson('/api/products', ['name' => 'Empty', 'category' => 'Food', 'price' => 0, 'stock' => 0])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$beta['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $this->getJson('/api/shop-admin/publications/filters')->assertOk()->assertExactJson(['categories' => ['Drinks', 'Food']]);
        foreach (['ALPHA-SKU', '123456789', 'Alpha'] as $q) {
            $this->getJson('/api/shop-admin/publications?q='.$q)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $alpha['id']);
        }
        $this->getJson('/api/shop-admin/publications?category=Drinks&stock_status=low_stock&publication_status=published&price_status=priced&sort=stock_asc')
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $beta['id']);
        $this->getJson('/api/shop-admin/publications?publication_status=unpublished')->assertJsonCount(2, 'items');
        $this->getJson('/api/shop-admin/publications?stock_status=out_of_stock')->assertJsonPath('items.0.id', $empty['id']);
        $this->getJson('/api/shop-admin/publications?stock_status=in_stock')->assertJsonCount(2, 'items');
        $this->getJson('/api/shop-admin/publications?price_status=missing')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $empty['id']);
        $this->getJson('/api/shop-admin/publications?sort=low_stock_first&limit=1')->assertJsonPath('items.0.id', $empty['id'])->assertJsonPath('has_more', true);
        $this->getJson('/api/shop-admin/publications?sort=low_stock_first&limit=1&offset=1')->assertJsonPath('items.0.id', $beta['id']);
        $this->getJson('/api/shop-admin/publications?sort=name_asc')->assertJsonPath('items.0.id', $alpha['id']);
        $this->getJson('/api/shop-admin/publications?sort=name_desc')->assertJsonPath('items.0.id', $this->product['id']);
        $this->getJson('/api/shop-admin/publications?sort=stock_desc')->assertJsonPath('items.0.id', $alpha['id']);
        $this->getJson('/api/shop-admin/publications?sort=price_desc')->assertJsonPath('items.0.id', $alpha['id']);
        $this->publish('purchase');
        DB::table('product_prices')->where('product_id', $alpha['id'])->update(['price' => 1500]);
        $this->getJson('/api/shop-admin/publications?sort=price_desc')->assertJsonPath('items.0.id', $this->product['id']);
        $this->getJson('/api/shop-admin/publications?sort=newest')->assertJsonPath('items.0.id', $empty['id']);
        $this->getJson('/api/shop-admin/publications?sort=oldest')->assertJsonPath('items.0.id', $this->product['id']);
        $this->getJson('/api/shop-admin/publications?sort=invalid')->assertUnprocessable();
        $this->getJson('/api/shop-admin/publications?stock_status=invalid')->assertUnprocessable();
        $this->postJson('/api/auth/register', ['business_name' => 'Other filters', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/publications/filters')->assertExactJson(['categories' => []]);
        $this->getJson('/api/shop-admin/publications?category=Drinks')->assertJsonCount(0, 'items');
    }

    public function test_shop_contacts_crud_supports_all_platforms_and_public_links(): void
    {
        $values = ['phone' => '+95 912345678', 'email' => 'shop@example.com', 'facebook' => 'https://www.facebook.com/shop',
            'tiktok' => 'https://www.tiktok.com/@shop', 'youtube' => 'https://www.youtube.com/@shop', 'telegram' => 'https://t.me/shop',
            'viber' => '+95 912345678', 'instagram' => 'https://www.instagram.com/shop', 'whatsapp' => '+95 912345678'];
        foreach ($values as $platform => $value) {
            $this->postJson('/api/shop-admin/contacts', ['platform' => $platform, 'label' => 'Sales', 'value' => $value, 'business_id' => 999])
                ->assertCreated()->assertJsonPath('platform', $platform)->assertJsonPath('value', $value)->assertJsonMissingPath('business_id');
        }
        $contacts = $this->getJson('/api/shop-admin/contacts')->assertOk()->assertJsonCount(9, 'items')->json('items');
        $this->assertSame('tel:+95912345678', $contacts[0]['url']);
        $this->assertSame('mailto:shop@example.com', $contacts[1]['url']);
        $this->assertSame('https://wa.me/95912345678', $contacts[8]['url']);
        $this->getJson($this->path(''))->assertOk()->assertJsonCount(9, 'contacts')->assertJsonMissingPath('contacts.0.business_id');
        $id = $contacts[0]['id'];
        $this->putJson('/api/shop-admin/contacts/'.$id, ['platform' => 'email', 'label' => 'Support', 'value' => 'support@example.com'])
            ->assertOk()->assertJsonPath('url', 'mailto:support@example.com');
        $this->getJson($this->path(''))->assertJsonPath('contacts.0.label', 'Support');
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->getJson('/api/shop-admin/contacts')->assertJsonCount(9, 'items');
        $this->deleteJson('/api/shop-admin/contacts/'.$id)->assertOk();
        $this->getJson($this->path(''))->assertJsonCount(8, 'contacts');
        $this->deleteJson('/api/shop-admin/contacts/'.$id)->assertNotFound();
    }

    public function test_shop_contact_validation_and_tenant_isolation(): void
    {
        foreach ([['phone', 'abc'], ['email', 'bad-email'], ['facebook', 'javascript:alert(1)'], ['telegram', 'ftp://example.com'],
            ['instagram', 'data:text/html,test'], ['whatsapp', '0912345678'], ['viber', '+95'], ['unknown', 'https://example.com'], ['phone', ['invalid']]] as [$platform, $value]) {
            $this->postJson('/api/shop-admin/contacts', ['platform' => $platform, 'value' => $value])->assertUnprocessable();
        }
        $contact = $this->postJson('/api/shop-admin/contacts', ['platform' => 'email', 'value' => 'first@example.com'])->assertCreated()->json();
        $this->postJson('/api/auth/register', ['business_name' => 'Other contact shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->getJson('/api/shop-admin/contacts')->assertForbidden()->assertJsonPath('code', 'shop_location_required');
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/contacts')->assertOk()->assertJsonCount(0, 'items');
        $this->putJson('/api/shop-admin/contacts/'.$contact['id'], ['platform' => 'email', 'value' => 'wrong@example.com'])->assertNotFound();
        $this->deleteJson('/api/shop-admin/contacts/'.$contact['id'])->assertNotFound();
        $this->getJson($this->path(''))->assertJsonPath('contacts.0.value', 'first@example.com');
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($staff, 'web');
        $this->postJson('/api/shop-admin/contacts', ['platform' => 'phone', 'value' => '0912345678'])->assertForbidden();
    }

    public function test_business_profile_is_shared_between_both_settings_and_public_shop(): void
    {
        [$orderId] = $this->order();
        $main = $this->getJson('/api/settings')->assertOk()->json();
        $this->putJson('/api/settings', array_replace($main, ['shop_name' => 'Shared shop', 'shop_address' => 'Market street',
            'shop_phone' => '091111', 'currency' => 'USD']))->assertOk();
        $store = $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('display_name', 'Shared shop')
            ->assertJsonPath('address', 'Market street')->assertJsonPath('phone', '091111')->assertJsonPath('currency', 'USD')->json();
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('display_name', 'Shared shop')
            ->assertJsonPath('address', 'Market street')->assertJsonPath('currency', 'USD');
        $this->postJson($this->path('/quote'), $this->input())->assertOk()->assertJsonPath('currency', 'USD');
        $this->getJson('/api/shop-admin/orders/'.$orderId)->assertOk()->assertJsonPath('currency', 'Ks');
        $this->putJson('/api/shop-admin/settings', array_replace($store, ['display_name' => 'Updated storefront',
            'address' => 'New address', 'phone' => '092222', 'currency' => 'EUR']))->assertOk();
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('shop_name', 'Updated storefront')
            ->assertJsonPath('shop_address', 'New address')->assertJsonPath('shop_phone', '092222')->assertJsonPath('currency', 'EUR');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('business.name', 'Updated storefront')->assertJsonPath('business.currency', 'EUR');
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('phone', '092222')->assertJsonPath('currency', 'EUR');
        unset($store['display_name'], $store['address'], $store['phone'], $store['currency']);
        $this->putJson('/api/shop-admin/settings', $store)->assertOk()->assertJsonPath('display_name', 'Updated storefront');
        $this->postJson('/api/auth/register', ['business_name' => 'Other profile', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('shop_name', 'Other profile')->assertJsonPath('shop_address', '')->assertJsonPath('currency', 'Ks');
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('display_name', 'Updated storefront')->assertJsonPath('address', 'New address');
    }

    public function test_storefront_logo_uses_shared_logo_and_staff_cannot_change_business_profile(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=');
        $this->post('/api/settings/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertOk();
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('receipt_logo_data_url', 'data:image/png;base64,'.base64_encode($png));
        $this->get($this->path('/logo'))->assertOk();
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Shop editor',
            'permissions' => json_encode(['ecommerce_settings']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->putJson('/api/shop-admin/settings', array_replace($this->settings, ['display_name' => 'Wrong', 'address' => 'Wrong',
            'phone' => 'Wrong', 'currency' => 'USD']))->assertOk()->assertJsonPath('display_name', 'Online Test Shop')->assertJsonPath('currency', 'Ks');
        $this->deleteJson('/api/settings/logo')->assertForbidden();
    }

    public function test_owner_submitted_shop_location_is_public_on_storefront_and_discovery(): void
    {
        $saved = $this->getJson('/api/shop-admin/owner-location')->assertOk()->assertJsonPath('submitted', true)
            ->assertJsonPath('location.latitude', 16.8409)->assertJsonPath('location.longitude', 96.1735)->assertJsonPath('location.accuracy', 10)
            ->assertJsonMissingPath('location.owner_id')->json('location.captured_at');
        $this->assertNotNull($saved);
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('location.latitude', 16.8409)->assertJsonPath('location.longitude', 96.1735)
            ->assertJsonMissingPath('location.owner_id')->assertJsonMissingPath('location.accuracy');
        $this->getJson('/api/ecommerce/marketplace/shops?q=Online%20Test%20Shop')->assertOk()->assertJsonPath('items.0.location.latitude', 16.8409);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 17.123, 'longitude' => 97.456, 'accuracy' => 10])->assertOk()
            ->assertJsonPath('location.latitude', 17.123)->assertJsonPath('location.longitude', 97.456);
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('location.latitude', 17.123)->assertJsonPath('location.longitude', 97.456);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 91, 'longitude' => 97, 'accuracy' => 10])->assertUnprocessable();
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com', 'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($staff, 'web')->postJson('/api/shop-admin/owner-location', ['latitude' => 17, 'longitude' => 97, 'accuracy' => 10])->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other location shop', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->getJson('/api/shop-admin/owner-location')->assertOk()->assertJsonPath('submitted', false)->assertJsonPath('location', null);
    }

    public function test_cash_default_backfill_preserves_receiving_accounts_and_cash_ids(): void
    {
        $businessId = $this->owner['business']['id'];
        $bank = ['id' => 'bank', 'name' => 'Wallet', 'type' => 'transfer', 'account_no' => '0912345', 'is_active' => true];
        $migration = require database_path('migrations/2026_10_05_000000_default_shop_cash_on_delivery.php');
        foreach ([[], [$bank], [$bank, ['id' => 'legacy-cash', 'name' => 'Cash', 'type' => 'cash', 'instructions' => 'Collect from buyer.']]] as $methods) {
            DB::table('storefront_settings')->where('business_id', $businessId)->update(['payment_methods' => json_encode($methods)]);
            $migration->up();
            $saved = json_decode(DB::table('storefront_settings')->where('business_id', $businessId)->value('payment_methods'), true);
            $this->assertSame('Cash on delivery', $saved[0]['name']);
            $this->assertSame('cash', $saved[0]['type']);
            $this->assertTrue($saved[0]['is_active']);
            if ($methods) $this->assertSame($bank, $saved[1]);
            if (count($methods) === 2) {
                $this->assertSame('legacy-cash', $saved[0]['id']);
                $this->assertSame('Collect from buyer.', $saved[0]['instructions']);
            }
            $migration->up();
            $this->assertSame($saved, json_decode(DB::table('storefront_settings')->where('business_id', $businessId)->value('payment_methods'), true));
        }
    }

    public function test_shop_payment_method_crud_filters_checkout_and_preserves_order_snapshots(): void
    {
        $platformCount = DB::table('payment_methods')->count();
        $method = $this->postJson('/api/shop-admin/payment-methods', ['name' => 'Shop wallet', 'type' => 'transfer',
            'bank' => 'Demo wallet', 'account_name' => 'Shop Owner', 'account_no' => '0911111111',
            'instructions' => 'Upload your receipt.', 'provider' => 'manual', 'is_active' => true,
            'secret_key' => 'must not be stored'])->assertCreated()->json();
        $this->assertArrayNotHasKey('secret_key', $method);
        $this->assertSame($platformCount, DB::table('payment_methods')->count());
        [$id, $result, $input] = $this->order(['payment_method_id' => $method['id']]);
        $this->putJson('/api/shop-admin/payment-methods/'.$method['id'], array_replace($method, ['account_no' => '0922222222', 'is_active' => false]))->assertOk();
        $this->getJson($this->path(''))->assertOk()->assertJsonCount(2, 'payment_methods');
        $this->postJson($this->path('/quote'), $this->input(['payment_method_id' => $method['id']]))->assertUnprocessable();
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('payment_method.account_no', '0911111111');
        $this->deleteJson('/api/shop-admin/payment-methods/'.$method['id'])->assertOk();
        $this->postJson($this->path('/checkout'), $input)->assertOk()->assertJsonPath('reference', $result['reference']);
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => 2000, 'reference' => 'Wallet checked', 'verified' => true])->assertOk();
        $this->deleteJson('/api/shop-admin/payment-methods/bank')->assertOk();
        $this->deleteJson('/api/shop-admin/payment-methods/cash')->assertUnprocessable();
        $this->putJson('/api/shop-admin/payment-methods/cash', ['name' => 'Cash', 'type' => 'cash', 'is_active' => false])->assertUnprocessable();
        $this->postJson('/api/shop-admin/payment-methods', ['name' => 'Card', 'type' => 'card', 'provider' => 'stripe'])->assertUnprocessable();
    }

    public function test_storefront_settings_save_does_not_overwrite_payment_methods(): void
    {
        $method = $this->postJson('/api/shop-admin/payment-methods', ['name' => 'Shop bank', 'type' => 'transfer',
            'bank' => 'Bank', 'account_name' => 'Owner', 'account_no' => '00123', 'is_active' => true])->assertCreated()->json();
        $settings = array_diff_key($this->settings, ['payment_methods' => true]);
        $this->putJson('/api/shop-admin/settings', $settings)->assertOk();
        $stored = collect($this->getJson('/api/shop-admin/payment-methods')->assertOk()->json('items'))->firstWhere('id', $method['id']);
        $this->assertSame('00123', $stored['account_no']);
        $settings['payment_methods'] = [$method];
        $this->putJson('/api/shop-admin/settings', $settings)->assertOk()->assertJsonPath('payment_methods.0.account_no', '00123');
        $settings['payment_methods'][0]['provider'] = 'unverified-gateway';
        $this->putJson('/api/shop-admin/settings', $settings)->assertUnprocessable();
    }

    public function test_manual_approval_requires_receipt_verification_and_records_the_reviewer(): void
    {
        [$id] = $this->order();
        $path = '/api/shop-admin/orders/'.$id.'/payments/confirm';
        $data = ['amount' => 2000, 'reference' => 'Receipt 42', 'review_note' => 'Cash counted at pickup.'];
        $this->postJson($path, $data)->assertUnprocessable()->assertJsonValidationErrors('verified');
        $this->postJson($path, $data + ['verified' => false])->assertUnprocessable();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'payment_status' => 'unpaid']);
        $this->postJson($path, array_replace($data, ['amount' => 1, 'verified' => true]))->assertUnprocessable();
        $this->postJson($path, $data + ['verified' => true])->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->postJson($path, $data + ['verified' => true])->assertOk();
        $this->postJson($path, array_replace($data, ['amount' => 1, 'verified' => true]))->assertUnprocessable();
        $payment = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'confirm')->sole();
        $this->assertSame($this->owner['user']['id'], $payment->actor_id);
        $this->assertSame($data['review_note'], $payment->reason);
        $this->assertDatabaseHas('ecommerce_order_events', ['order_id' => $id, 'event' => 'payment_confirm', 'actor_id' => $payment->actor_id, 'reason' => $data['review_note']]);
    }

    public function test_shop_payment_methods_are_tenant_scoped(): void
    {
        $method = $this->postJson('/api/shop-admin/payment-methods', ['name' => 'Private shop account', 'type' => 'transfer'])->assertCreated()->json();
        $this->postJson('/api/auth/register', ['business_name' => 'Other Shop', 'owner_name' => 'Other Owner',
            'email' => 'payment-other-'.Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/payment-methods')->assertOk()->assertJsonCount(1, 'items');
        $this->putJson('/api/shop-admin/payment-methods/'.$method['id'], ['name' => 'Stolen', 'type' => 'cash'])->assertNotFound();
        $this->deleteJson('/api/shop-admin/payment-methods/'.$method['id'])->assertNotFound();
        $methods = json_decode(DB::table('storefront_settings')->where('business_id', $this->owner['business']['id'])->value('payment_methods'), true);
        $this->assertSame('Private shop account', collect($methods)->firstWhere('id', $method['id'])['name']);
    }

    public function test_payment_and_refund_references_can_be_omitted_null_or_blank(): void
    {
        foreach ([[], ['reference' => null], ['reference' => '']] as $reference) {
            [$id] = $this->order();
            $payment = ['amount' => 2000, 'verified' => true] + $reference;
            $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', $payment)->assertOk()->assertJsonPath('payment_status', 'paid');
            $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', $payment)->assertOk();
            $this->assertNull(DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'confirm')->sole()->reference);
            $this->transition($id, 'cancelled', ['reason' => 'Customer cancelled'])->assertOk();
            $this->postJson('/api/shop-admin/orders/'.$id.'/payments/refund', $payment)->assertOk()->assertJsonPath('payment_status', 'refunded');
            $this->assertNull(DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'refund')->sole()->reference);
        }
    }

    public function test_future_gateway_payments_cannot_be_approved_by_the_manual_endpoint(): void
    {
        Storage::fake('local');
        [$id, $result] = $this->order();
        DB::table('ecommerce_orders')->where('id', $id)->update(['payment_method' => json_encode(['id' => 'bank', 'name' => 'Future bank', 'type' => 'transfer', 'provider' => 'future-gateway'])]);
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => 2000, 'reference' => 'Attempt', 'verified' => true])->assertConflict();
        $this->post('/api/ecommerce/proofs', ['reference' => $result['reference'], 'credential' => $result['tracking_credential'],
            'proof' => UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6xoAAAAASUVORK5CYII='))], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame(0, DB::table('ecommerce_payments')->where('order_id', $id)->count());
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'payment_status' => 'unpaid']);
    }

    public function test_product_crud_owns_the_shared_online_description(): void
    {
        $id = $this->product['id'];
        $this->getJson('/api/products/'.$id)->assertOk()->assertJsonPath('online_description', 'Public description');
        $input = ['name' => 'Test rice', 'price' => 1000, 'cost' => 600, 'stock' => 10,
            'base_unit' => 'Bag', 'purchase_unit' => 'Box', 'purchase_conversion_factor' => 2,
            'online_description' => "Updated in Products\nDetails for customers."];
        $this->putJson('/api/products/'.$id, $input)->assertOk()->assertJsonPath('online_description', $input['online_description']);
        $this->getJson('/api/products?ids='.$id.'&with_total=1')->assertOk()->assertJsonPath('items.0.online_description', $input['online_description']);
        $this->getJson('/api/shop-admin/publications')->assertOk()->assertJsonPath('items.0.description', $input['online_description']);
        $this->getJson($this->path('/products/'.$id))->assertOk()->assertJsonPath('description', $input['online_description']);
        // Publication saves must not overwrite descriptions edited in Products.
        $this->putJson('/api/shop-admin/publications/'.$id, ['published' => true, 'unit' => 'purchase'])->assertOk();
        $this->putJson('/api/products/'.$id, array_diff_key($input, ['online_description' => true]))->assertOk()
            ->assertJsonPath('online_description', $input['online_description']);
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $id, 'published' => true, 'unit' => 'purchase']);
        $this->putJson('/api/products/'.$id, array_replace($input, ['online_description' => str_repeat('x', 4001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('online_description');
        $this->putJson('/api/products/'.$id, array_replace($input, ['online_description' => '']))->assertOk()->assertJsonPath('online_description', '');
        $this->getJson($this->path('/products/'.$id))->assertOk()->assertJsonPath('description', null);
    }

    public function test_creating_an_online_description_does_not_publish_the_product(): void
    {
        $product = $this->postJson('/api/products', ['name' => 'Draft product', 'price' => 1000, 'stock' => 3,
            'online_description' => 'Draft shop description'])->assertOk()->assertJsonPath('online_description', 'Draft shop description')->json();
        $this->assertDatabaseHas('ecommerce_product_settings', ['product_id' => $product['id'], 'published' => false, 'unit' => 'base', 'description' => 'Draft shop description']);
        $this->getJson($this->path('/products/'.$product['id']))->assertNotFound();
    }

    public function test_order_date_filter_uses_inclusive_business_local_days(): void
    {
        DB::table('businesses')->where('id', $this->owner['business']['id'])->update(['timezone' => 'Asia/Yangon']);
        [$id] = $this->order();
        $range = '/api/shop-admin/orders?date_from=2026-10-05&date_to=2026-10-05';
        foreach (['2026-10-05 00:00:00', '2026-10-05 23:59:59'] as $time) {
            DB::table('ecommerce_orders')->where('id', $id)->update(['created_at' => $time]);
            $this->getJson($range)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $id);
        }
        foreach (['2026-10-04 23:59:59', '2026-10-06 00:00:00'] as $time) {
            DB::table('ecommerce_orders')->where('id', $id)->update(['created_at' => $time]);
            $this->getJson($range)->assertOk()->assertJsonCount(0, 'items');
        }
        $this->getJson('/api/shop-admin/orders?date_from=2026-10-05')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/shop-admin/orders?date_to=2026-10-05')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/shop-admin/orders?date_from=&date_to=')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/shop-admin/orders?date_from=2026-10-06&date_to=2026-10-05')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/shop-admin/orders?date_from=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('date_from');
    }

    public function test_order_date_filter_combines_with_status_search_and_pagination(): void
    {
        [$first] = $this->order();
        [$second] = $this->order();
        DB::table('ecommerce_orders')->whereIn('id', [$first, $second])->update(['created_at' => '2026-10-05 06:00:00']);
        $path = '/api/shop-admin/orders?date_from=2026-10-05&date_to=2026-10-05&status=pending&payment_status=unpaid&q=Guest&limit=1';
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $second)->assertJsonPath('has_more', true)->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.fulfillment', 'pickup')->assertJsonPath('items.0.payment_method.type', 'cash');
        $this->getJson($path.'&offset=1')->assertOk()->assertJsonPath('items.0.id', $first)->assertJsonPath('has_more', false)->assertJsonPath('total', 2);
        $this->getJson(str_replace('status=pending', 'status=completed', $path))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('total', 0);
    }

    public function test_checkout_snapshots_prices_and_is_idempotent_even_after_shop_disabled(): void
    {
        [$id, $result, $data] = $this->order();
        $this->postJson($this->path('/checkout'), $data)->assertOk()->assertJsonPath('reference', $result['reference'])->assertJsonPath('tracking_credential', $result['tracking_credential']);
        $this->postJson($this->path('/checkout'), array_replace($data, ['phone' => 'different']))->assertStatus(409);
        $this->assertSame(1, DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->count());
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'stock' => 10]);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'quantity' => 2, 'status' => 'active']);
        $this->assertSame(0, DB::table('sales')->where('business_id', $this->owner['business']['id'])->count());
        DB::table('storefront_settings')->where('id', $this->settings['id'])->update(['storefront_enabled' => false]);
        $this->postJson($this->path('/checkout'), $data)->assertOk()->assertJsonPath('reference', $result['reference']);
        $this->postJson('/api/ecommerce/tracking', ['reference' => $result['reference'], 'credential' => $result['tracking_credential']])->assertOk();
    }

    public function test_changed_quote_and_cross_business_items_are_rejected(): void
    {
        $data = $this->input();
        $quote = $this->postJson($this->path('/quote'), $data)->assertOk()->json();
        DB::table('product_prices')->where('product_id', $this->product['id'])->update(['price' => 2000]);
        $this->postJson($this->path('/checkout'), $data + ['quote_hash' => $quote['quote_hash']])->assertStatus(409);
        $this->postJson($this->path('/quote'), array_replace($data, ['items' => [['product_id' => 999999999, 'quantity' => 1]]]))->assertUnprocessable();
        $this->postJson($this->path('/quote'), array_replace($data, ['delivery_zone_id' => 'unsupported', 'fulfillment' => 'delivery']))->assertUnprocessable();
        $this->postJson($this->path('/quote'), array_replace($data, ['items' => [['product_id' => $this->product['id'], 'quantity' => 1], ['product_id' => $this->product['id'], 'quantity' => 1]]]))->assertUnprocessable();
    }

    public function test_reservations_protect_pos_sales_stock_edits_archival_and_offline_sync(): void
    {
        $this->order(['items' => [['product_id' => $this->product['id'], 'quantity' => 8]]]);
        $this->postJson($this->path('/quote'), $this->input(['items' => [['product_id' => $this->product['id'], 'quantity' => 3]]]))->assertStatus(409);
        $sale = ['items' => [['product_id' => $this->product['id'], 'product_name' => $this->product['name'], 'price_type' => 'Retail', 'quantity' => 3, 'unit_price' => 1000]], 'paid_amount' => 3000];
        $this->postJson('/api/sales', $sale)->assertStatus(409);
        $this->postJson('/api/sales/offline-sync', $sale + ['offline_sale_uuid' => (string) Str::uuid(), 'offline_created_at' => now()->toISOString()])->assertStatus(409);
        $this->postJson('/api/products/'.$this->product['id'].'/adjust-stock', ['quantity' => -3])->assertStatus(409);
        $this->deleteJson('/api/products/'.$this->product['id'])->assertStatus(409);
        $this->putJson('/api/products/'.$this->product['id'], array_replace($this->product, ['purchase_conversion_factor' => 3]))->assertStatus(409);
        $this->getJson('/api/products/'.$this->product['id'])->assertOk()->assertJsonPath('reserved_stock', 8)->assertJsonPath('available_stock', 2);
        $this->postJson('/api/sales', array_replace($sale, ['items' => [array_replace($sale['items'][0], ['quantity' => 2])], 'paid_amount' => 2000]))->assertOk();
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'stock' => 8]);
    }

    public function test_sales_channel_filters_include_both_connected_and_offline_pos_sales(): void
    {
        [$id] = $this->order();
        $this->pay($id);
        foreach (['accepted', 'packing', 'ready_for_pickup', 'completed'] as $state) $this->transition($id, $state)->assertOk();
        $onlineId = DB::table('ecommerce_orders')->where('id', $id)->value('sale_id');
        $pos = $this->postJson('/api/sales', ['items' => [['product_id' => $this->product['id'], 'quantity' => 1, 'unit_price' => 1000]],
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 1000])->assertOk()->json();
        $this->getJson('/api/sales?status=completed&sale_channel=online&with_total=true&limit=1&offset=0')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $onlineId)->assertJsonPath('items.0.source', 'ecommerce');
        foreach (['online', 'offline'] as $source) {
            DB::table('sales')->where('id', $pos['id'])->update(['source' => $source]);
            $this->getJson('/api/sales?status=completed&sale_channel=pos&with_total=true&limit=1&offset=0')->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $pos['id']);
        }
        $this->getJson('/api/sales?status=completed&sale_channel=all&with_total=true')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/sales?sale_channel=invalid')->assertUnprocessable();
    }

    public function test_completion_is_atomic_uses_snapshots_and_creates_one_sale(): void
    {
        $this->publish('purchase');
        [$id] = $this->order();
        foreach (['accepted', 'packing', 'ready_for_pickup'] as $state) $this->transition($id, $state)->assertOk();
        $this->transition($id, 'completed')->assertStatus(409);
        $this->pay($id, 4000); $this->pay($id, 4000);
        DB::table('products')->where('id', $this->product['id'])->update(['name' => 'New name', 'price' => 9000]);
        DB::table('product_prices')->where('product_id', $this->product['id'])->update(['price' => 9000]);
        $this->transition($id, 'completed')->assertOk(); $this->transition($id, 'completed')->assertOk();
        $sale = DB::table('sales')->where('business_id', $this->owner['business']['id'])->sole();
        $this->assertSame(4000, (int) $sale->total);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'product_name' => 'Test rice', 'quantity' => 4, 'ecommerce_quantity' => 2, 'ecommerce_unit_price' => 2000]);
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'stock' => 6]);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'consumed']);
        $this->deleteJson('/api/sales/'.$sale->id, ['admin_pin' => ''])->assertOk();
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('payment_status', 'refund_pending');
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'stock' => 10]);
        $this->transition($id, 'completed')->assertOk();
        $this->assertSame(0, DB::table('sales')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_failed_delivery_holds_stock_until_return_and_records_refund_obligation(): void
    {
        [$id] = $this->order(['fulfillment' => 'delivery', 'delivery_zone_id' => 'city', 'address' => '1 Main St']);
        $this->pay($id, 2500);
        foreach (['accepted', 'packing', 'dispatched'] as $state) $this->transition($id, $state)->assertOk();
        $this->transition($id, 'cancelled', ['reason' => 'Failed delivery'])->assertStatus(409);
        $this->transition($id, 'return_pending', ['reason' => 'Customer absent'])->assertOk();
        $this->transition($id, 'cancelled', ['reason' => 'Returned'])->assertUnprocessable();
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active']);
        $this->transition($id, 'cancelled', ['reason' => 'Returned', 'return_confirmed' => true])->assertOk()->assertJsonPath('payment_status', 'refund_pending');
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/refund', ['amount' => 2500, 'reference' => 'Refund 1', 'verified' => true])->assertOk()->assertJsonPath('payment_status', 'refunded');
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/refund', ['amount' => 2500, 'reference' => 'Refund 1', 'verified' => true])->assertOk();
        $this->assertSame(1, DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'refund')->count());
    }

    public function test_tracking_and_proofs_are_scoped_and_require_review(): void
    {
        Storage::fake('local');
        [$id, $result] = $this->order(['payment_method_id' => 'bank']);
        $tracking = ['reference' => $result['reference'], 'credential' => $result['tracking_credential']];
        $this->postJson('/api/ecommerce/tracking', array_replace($tracking, ['credential' => str_repeat('0', 64)]))->assertNotFound();
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6xoAAAAASUVORK5CYII=');
        $this->postJson('/api/ecommerce/proofs', $tracking + ['proof' => UploadedFile::fake()->createWithContent('proof.png', $image)])->assertOk();
        $this->assertSame(1, DB::table('realtime_activity_events')->where('audience', 'owner')->where('business_id', $this->owner['business']['id'])->where('event', 'proof_submitted')->count());
        $view = $this->postJson('/api/ecommerce/tracking', $tracking)->assertOk()->assertJsonPath('payment_status', 'proof_submitted')->json();
        foreach (['phone', 'email', 'address', 'tracking_hash', 'idempotency_key', 'events', 'payments'] as $key) $this->assertArrayNotHasKey($key, $view);
        $proof = DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'proof')->sole();
        $this->get('/api/shop-admin/orders/'.$id.'/proofs/'.$proof->id)->assertOk();
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/reject', ['reason' => 'Please upload a readable proof'])->assertOk();
        $this->postJson('/api/ecommerce/tracking', $tracking)->assertOk()->assertJsonPath('payment_reviews.0.reason', 'Please upload a readable proof');
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => 1, 'reference' => 'Partial', 'verified' => true])->assertUnprocessable();
    }

    public function test_shop_orders_never_expire_or_release_stock_automatically(): void
    {
        [$unpaid] = $this->order(); [$paid] = $this->order(); [$accepted] = $this->order();
        $this->assertNull(DB::table('ecommerce_orders')->where('id', $unpaid)->value('expires_at'));
        $this->pay($paid); $this->transition($accepted, 'accepted')->assertOk();
        DB::table('ecommerce_orders')->where('business_id', $this->owner['business']['id'])->update(['expires_at' => now()->subDay()]);
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $unpaid, 'status' => 'pending']);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $paid, 'status' => 'pending']);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $accepted, 'status' => 'accepted']);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $unpaid, 'status' => 'active']);
        try {
            app(EcommerceService::class)->transition($this->owner['business']['id'], $unpaid, 'expired', null);
            $this->fail('Shop orders must never expire, even with an old stored deadline.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        DB::table('business_subscriptions')->where('business_id', $this->owner['business']['id'])->update(['ends_at' => now()->subDay()]);
        $this->transition($accepted, 'cancelled', ['reason' => 'Subscription expired'])->assertOk();
        $this->getJson('/api/shop-admin/orders')->assertOk();
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertStatus(402);
    }

    public function test_delivery_orders_have_no_deadline_and_can_be_processed_later(): void
    {
        [$id] = $this->order(['fulfillment' => 'delivery', 'delivery_zone_id' => 'city', 'address' => 'Buyer address']);
        $this->assertNull(DB::table('ecommerce_orders')->where('id', $id)->value('expires_at'));
        $this->travel(365)->days();
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active']);
        $this->transition($id, 'accepted')->assertOk();
        $this->travelBack();
    }

    public function test_delivery_service_fee_is_separate_from_shop_payment_and_sale(): void
    {
        [$id, $result] = $this->order(['fulfillment' => 'delivery', 'delivery_zone_id' => '__delivery_service__', 'address' => 'Buyer address']);
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('delivery_fee_recipient', 'delivery_service')
            ->assertJsonPath('delivery_fee', 0)->assertJsonPath('total', 2000);
        $this->postJson('/api/ecommerce/tracking', ['reference' => $result['reference'], 'credential' => $result['tracking_credential']])
            ->assertOk()->assertJsonPath('delivery_fee_recipient', 'delivery_service')->assertJsonPath('total', 2000);
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => 2500, 'verified' => true])->assertUnprocessable();
        $this->settings['delivery_zones'][0]['fee_recipient'] = 'shop';
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->pay($id);
        foreach (['accepted', 'packing', 'dispatched', 'completed'] as $status) $this->transition($id, $status)->assertOk();
        $sale = DB::table('sales')->where('id', DB::table('ecommerce_orders')->where('id', $id)->value('sale_id'))->first();
        $this->assertSame(2000, (int) $sale->total);
        $this->assertSame(0, (int) $sale->delivery_fee);
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'delivery_fee' => 0, 'delivery_fee_recipient' => 'delivery_service']);
    }

    public function test_delivery_zone_crud_preserves_other_settings_and_existing_order_snapshots(): void
    {
        $created = $this->postJson('/api/shop-admin/delivery-zones', ['name' => 'Town', 'fee' => 300])->assertCreated()->json();
        $this->getJson('/api/shop-admin/delivery-zones')->assertOk()->assertJsonCount(2, 'items');
        [$id] = $this->order(['fulfillment' => 'delivery', 'delivery_zone_id' => $created['id'], 'address' => 'Buyer address']);
        $this->putJson('/api/shop-admin/delivery-zones/'.$created['id'], ['name' => 'New Town', 'fee' => 900])->assertOk()->assertJsonPath('id', $created['id']);
        $this->deleteJson('/api/shop-admin/delivery-zones/'.$created['id'])->assertOk();
        $this->getJson('/api/shop-admin/delivery-zones')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 'city');
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('delivery_fee', 300)->assertJsonPath('total', 2300)
            ->assertJsonPath('fulfillment_instructions', 'Town');
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('display_name', $this->settings['display_name'])
            ->assertJsonCount(2, 'payment_methods');
        $this->putJson('/api/shop-admin/delivery-zones/city', ['name' => '', 'fee' => -1])->assertUnprocessable();
        $this->deleteJson('/api/shop-admin/delivery-zones/'.$created['id'])->assertNotFound();
    }

    public function test_delivery_zone_crud_is_tenant_scoped(): void
    {
        $this->postJson('/api/auth/register', ['business_name' => 'Other zone shop', 'owner_name' => 'Other owner',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/delivery-zones')->assertOk()->assertJsonCount(0, 'items');
        $this->putJson('/api/shop-admin/delivery-zones/city', ['name' => 'Stolen', 'fee' => 10])->assertNotFound();
        $this->deleteJson('/api/shop-admin/delivery-zones/city')->assertNotFound();
        $this->assertDatabaseHas('storefront_settings', ['business_id' => $this->owner['business']['id'], 'display_name' => $this->settings['display_name']]);
    }

    public function test_zone_fee_is_charged_to_shop_and_direct_service_works_without_zones(): void
    {
        $data = $this->input(['fulfillment' => 'delivery', 'delivery_zone_id' => 'city', 'address' => 'Buyer address']);
        $quote = $this->postJson($this->path('/quote'), $data)->assertOk()->assertJsonPath('total', 2500)
            ->assertJsonPath('delivery_fee_recipient', 'shop')->json();
        $this->settings['delivery_zones'] = [];
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->postJson($this->path('/quote'), $data)->assertUnprocessable();
        $data['delivery_zone_id'] = '__delivery_service__';
        $directQuote = $this->postJson($this->path('/quote'), $data)->assertOk()->assertJsonPath('total', 2000)
            ->assertJsonPath('delivery_fee', 0)->assertJsonPath('delivery_fee_recipient', 'delivery_service')->json();
        $this->postJson($this->path('/checkout'), $data + ['quote_hash' => $quote['quote_hash']])->assertConflict();
        $this->postJson($this->path('/checkout'), $data + ['quote_hash' => $directQuote['quote_hash']])->assertOk();
    }

    public function test_pending_proof_review_prevents_scheduled_and_direct_expiry(): void
    {
        [$id] = $this->order(['payment_method_id' => 'bank']);
        DB::table('ecommerce_orders')->where('id', $id)->update(['payment_status' => 'proof_submitted', 'expires_at' => now()->subDay()]);
        DB::table('ecommerce_payments')->insert(['business_id' => $this->owner['business']['id'], 'order_id' => $id, 'type' => 'proof', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'status' => 'pending', 'payment_status' => 'proof_submitted']);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active']);
        try {
            app(EcommerceService::class)->transition($this->owner['business']['id'], $id, 'expired', null);
            $this->fail('Direct expiry must recheck payment status inside the order lock.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->pay($id);
        $this->transition($id, 'accepted')->assertOk();
        $this->transition($id, 'packing')->assertOk();
        $this->transition($id, 'ready_for_pickup')->assertOk();
        $this->transition($id, 'completed')->assertOk();
    }

    public function test_rejected_proof_has_no_resubmission_deadline(): void
    {
        [$id] = $this->order(['payment_method_id' => 'bank']);
        DB::table('ecommerce_orders')->where('id', $id)->update(['payment_status' => 'proof_submitted', 'expires_at' => now()->subDay()]);
        DB::table('ecommerce_payments')->insert(['business_id' => $this->owner['business']['id'], 'order_id' => $id, 'type' => 'proof', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/reject', ['reason' => 'Receipt unreadable'])->assertOk()->assertJsonPath('payment_status', 'unpaid');
        $this->assertNull(DB::table('ecommerce_orders')->where('id', $id)->value('expires_at'));
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'status' => 'pending']);
        DB::table('ecommerce_orders')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'status' => 'pending']);
    }

    private function legacyExpiredProof(): int
    {
        [$id] = $this->order(['payment_method_id' => 'bank']);
        DB::table('ecommerce_orders')->where('id', $id)->update(['expires_at' => now()->subDay()]);
        DB::table('ecommerce_orders')->where('id', $id)->update(['status' => 'expired']);
        DB::table('ecommerce_stock_reservations')->where('order_id', $id)->update(['status' => 'released']);
        // Reproduce the old scheduler bug: proof existed when expiry released stock.
        DB::table('ecommerce_orders')->where('id', $id)->update(['payment_status' => 'proof_submitted']);
        DB::table('ecommerce_payments')->insert(['business_id' => $this->owner['business']['id'], 'order_id' => $id, 'type' => 'proof', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_expired_proof_orders_can_reopen_with_atomic_stock_reservation(): void
    {
        $id = $this->legacyExpiredProof();
        $this->postJson('/api/shop-admin/orders/'.$id.'/reopen')->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('payment_status', 'proof_submitted');
        $this->postJson('/api/shop-admin/orders/'.$id.'/reopen')->assertOk();
        $this->assertSame(1, DB::table('ecommerce_stock_reservations')->where('order_id', $id)->count());
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active', 'quantity' => 2]);
        $this->assertSame(1, DB::table('ecommerce_order_events')->where('order_id', $id)->where('event', 'reopened')->count());
        $this->artisan('ecommerce:expire-orders')->assertSuccessful();
        $this->pay($id);
        $this->transition($id, 'accepted')->assertOk();
    }

    public function test_insufficient_stock_prevents_reopening_and_late_payment_can_be_refunded(): void
    {
        $id = $this->legacyExpiredProof();
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 1]);
        $this->postJson('/api/shop-admin/orders/'.$id.'/reopen')->assertConflict();
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'status' => 'expired', 'payment_status' => 'proof_submitted']);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'released']);
        $data = ['amount' => 2000, 'reference' => 'Verified late transfer', 'verified' => true];
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', $data)->assertOk()->assertJsonPath('payment_status', 'refund_pending')->assertJsonPath('status', 'expired');
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', $data)->assertOk();
        $this->assertSame(1, DB::table('ecommerce_payments')->where('order_id', $id)->where('type', 'confirm')->count());
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'released']);
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/refund', ['amount' => 2000, 'reference' => 'Refund sent', 'verified' => true])->assertOk()->assertJsonPath('payment_status', 'refunded');
        $this->assertSame(0, DB::table('sales')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_reopen_is_tenant_scoped_and_requires_processing_permission(): void
    {
        $id = $this->legacyExpiredProof();
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Payment reviewer',
            'permissions' => json_encode(['ecommerce_orders', 'ecommerce_payments']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Reviewer', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->postJson('/api/shop-admin/orders/'.$id.'/reopen')->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other recovery shop', 'owner_name' => 'Other owner',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->postJson('/api/shop-admin/orders/'.$id.'/reopen')->assertNotFound();
    }

    public function test_marketplace_opt_out_and_suspension_do_not_disable_storefront(): void
    {
        $this->getJson('/api/ecommerce/marketplace/shops?q=Online%20Test%20Shop')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/ecommerce/marketplace/products?q=Test%20rice')->assertOk()->assertJsonCount(1, 'items');
        DB::table('storefront_settings')->where('id', $this->settings['id'])->update(['marketplace_suspended' => true]);
        $this->getJson('/api/ecommerce/marketplace/shops?q=Online%20Test%20Shop')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/marketplace/products?q=Test%20rice')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->path('/products'))->assertOk()->assertJsonCount(1, 'items');
        $this->postJson($this->path('/quote'), $this->input(['origin' => 'marketplace']))->assertStatus(409);
    }

    public function test_cross_business_staff_order_and_publication_access_are_denied(): void
    {
        [$id] = $this->order();
        $second = $this->postJson('/api/auth/register', ['business_name' => 'Second shop', 'owner_name' => 'Second', 'email' => Str::uuid().'@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/orders/'.$id)->assertNotFound();
        $this->transition($id, 'accepted')->assertNotFound();
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => true, 'unit' => 'base'])->assertNotFound();
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => 2000, 'reference' => 'Attempt', 'verified' => true])->assertNotFound();
        $this->assertNotSame($second['business']['id'], $this->owner['business']['id']);
    }

    public function test_completion_failure_rolls_back_consumed_reservations_and_sale_link(): void
    {
        [$id] = $this->order();
        foreach (['accepted', 'packing', 'ready_for_pickup'] as $state) $this->transition($id, $state)->assertOk();
        $this->pay($id);
        $posting = $this->createMock(\App\Services\SalePostingService::class);
        $posting->method('save')->willThrowException(new \RuntimeException('Fixture posting failure'));
        $this->app->instance(\App\Services\SalePostingService::class, $posting);
        try {
            app(EcommerceService::class)->transition($this->owner['business']['id'], $id, 'completed', $this->owner['user']['id']);
            $this->fail('Posting failure should propagate.');
        } catch (\RuntimeException $e) { $this->assertSame('Fixture posting failure', $e->getMessage()); }
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'status' => 'ready_for_pickup', 'sale_id' => null]);
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active']);
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'stock' => 10]);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_purchase_reversal_and_data_reset_preserve_order_dependencies(): void
    {
        $purchaseData = ['items' => [['product_id' => $this->product['id'], 'quantity' => 5, 'unit_cost' => 600]]];
        $purchase = $this->postJson('/api/purchases', $purchaseData)->assertOk()->json();
        $this->order(['items' => [['product_id' => $this->product['id'], 'quantity' => 12]]]);
        // A metadata-only purchase edit must not fail because of a temporary
        // reversal inside the same locked transaction. Validate the final stock.
        $this->putJson('/api/purchases/'.$purchase['id'], $purchaseData + ['note' => 'Metadata only'])->assertOk();
        $this->deleteJson('/api/purchases/'.$purchase['id'])->assertStatus(409);
        $this->putJson('/api/purchases/'.$purchase['id'], ['items' => [['product_id' => $this->product['id'], 'quantity' => 1, 'unit_cost' => 600]]])->assertStatus(409);
        $this->postJson('/api/data/reset', ['confirmation' => 'RESET'])->assertStatus(409);
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'stock' => 15]);
        $this->assertDatabaseHas('purchases', ['id' => $purchase['id']]);
    }

    public function test_shop_permissions_do_not_grant_private_pos_or_payment_access(): void
    {
        [$id] = $this->order();
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Shop viewer',
            'permissions' => json_encode(['ecommerce_orders', 'ecommerce_settings']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Shop staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk();
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('price_types.0', 'Retail');
        $this->getJson('/api/shop-admin/payment-methods')->assertOk();
        $this->getJson('/api/products')->assertForbidden();
        $this->transition($id, 'accepted')->assertForbidden();
        $this->postJson('/api/shop-admin/orders/'.$id.'/payments/confirm', ['amount' => 2000, 'reference' => 'Unauthorized'])->assertForbidden();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonFragment(['shop']);
    }

    public function test_publication_photos_are_available_to_catalog_staff_and_tenant_scoped(): void
    {
        Storage::fake('local');
        $photoPath = 'products/publication-test.jpg';
        Storage::disk('local')->put($photoPath, 'test-photo');
        DB::table('products')->where('id', $this->product['id'])->update(['photo_path' => $photoPath]);
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => false, 'unit' => 'base'])->assertOk();
        $photoUrl = $this->getJson('/api/shop-admin/publications')->assertOk()->json('items.0.photo_url');
        $this->assertStringStartsWith('/shop-admin/publications/'.$this->product['id'].'/photo?v=', $photoUrl);
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Catalog staff',
            'permissions' => json_encode(['ecommerce_catalog']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Catalog staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->get('/api'.$photoUrl)->assertOk();
        $this->getJson('/api/products/'.$this->product['id'].'/photo')->assertForbidden();
        DB::table('access_roles')->where('id', $role)->update(['permissions' => json_encode(['ecommerce_orders'])]);
        $this->get('/api'.$photoUrl)->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other photos', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->get('/api'.$photoUrl)->assertNotFound();
    }

    public function test_promotion_filters_use_schedule_overlap_business_dates_and_stable_sorting(): void
    {
        [$first] = $this->promotion('coupon', ['name' => 'Zulu', 'code' => 'FILTER1']);
        [$second] = $this->promotion('coupon', ['name' => 'Alpha', 'code' => 'FILTER2', 'starts_at' => '2026-10-15T09:00', 'ends_at' => '2026-10-16T00:00']);
        [$third] = $this->promotion('coupon', ['name' => 'Beta', 'code' => 'FILTER3', 'starts_at' => '2026-10-16T00:00', 'ends_at' => '2026-10-17T00:00']);
        $this->getJson('/api/shop-admin/promotions?sort=newest&limit=1')->assertOk()->assertJsonPath('items.0.id', $third);
        $this->getJson('/api/shop-admin/promotions?sort=oldest&limit=1&offset=1')->assertOk()->assertJsonPath('items.0.id', $second);
        $this->getJson('/api/shop-admin/promotions?sort=name&limit=1')->assertOk()->assertJsonPath('items.0.id', $second);
        $ids = $this->getJson('/api/shop-admin/promotions?date_from=2026-10-15&date_to=2026-10-15')->assertOk()->json('items');
        $this->assertEqualsCanonicalizing([$first, $second], array_column($ids, 'id'));
        $ids = $this->getJson('/api/shop-admin/promotions?date_from=2026-10-16&date_to=2026-10-16')->assertOk()->json('items');
        $this->assertEqualsCanonicalizing([$first, $third], array_column($ids, 'id'));
        $this->getJson('/api/shop-admin/promotions?date_from=2026-10-17&date_to=2026-10-15')->assertUnprocessable();
        $this->getJson('/api/shop-admin/promotions?sort=unknown')->assertUnprocessable();
    }

    private function promotion(string $type, array $extra = []): array
    {
        $data = array_replace(['type' => $type, 'name' => 'Promotion test', 'enabled' => true,
            'discount_type' => 'percentage', 'discount_value' => 20, 'product_id' => $type === 'flash_sale' ? $this->product['id'] : null,
            'code' => $type === 'coupon' ? 'SAVE20' : null, 'quantity_limit' => null, 'usage_limit' => null,
            'minimum_subtotal' => 0, 'starts_at' => null, 'ends_at' => null], $extra);
        $id = $this->postJson('/api/shop-admin/promotions', $data)->assertOk()->json('id');
        return [$id, $data];
    }

    public function test_multi_product_flash_sales_save_atomically_and_reject_duplicate_products(): void
    {
        $second = $this->postJson('/api/products', ['name' => 'Test tea', 'price' => 2000, 'stock' => 10])->assertOk()->json('id');
        $this->putJson('/api/shop-admin/publications/'.$second, ['published' => true, 'unit' => 'base'])->assertOk();
        $data = ['type' => 'flash_sale', 'name' => 'Weekend', 'product_ids' => [$this->product['id'], $second],
            'discount_type' => 'percentage', 'discount_value' => 20, 'quantity_limit' => 2.5,
            'starts_at' => '2026-10-08T09:00', 'ends_at' => '2026-10-09T09:00', 'enabled' => true];
        $ids = $this->postJson('/api/shop-admin/promotions', $data)->assertOk()->assertJsonCount(2, 'ids')->json('ids');
        foreach ($ids as $id) $this->assertDatabaseHas('shop_promotions', ['id' => $id, 'name' => 'Weekend', 'quantity_limit' => 2.5]);
        $this->postJson('/api/shop-admin/promotions', array_replace($data, ['product_ids' => [$second, $second]]))->assertUnprocessable()->assertJsonValidationErrors('product_ids.0');
        // The first product inserts successfully, but the later conflict must roll it back.
        $this->deleteJson('/api/shop-admin/promotions/'.$ids[0])->assertOk();
        $this->postJson('/api/shop-admin/promotions', array_replace($data, ['name' => 'Rejected event']))
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseMissing('shop_promotions', ['name' => 'Rejected event']);
        $this->postJson('/api/shop-admin/promotions', array_replace($data, ['product_ids' => [$this->product['id'], 2147483647], 'name' => 'Invalid product event']))->assertUnprocessable();
        $this->assertDatabaseMissing('shop_promotions', ['name' => 'Invalid product event']);
    }

    public function test_reviews_require_completed_account_purchases_and_are_unique_per_target(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->withHeader('Authorization', '');
        $this->putJson($this->path('/reviews/mine'), ['rating' => 5])->assertUnauthorized();
        $account = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Review Buyer', 'email' => Str::uuid().'@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $this->getJson($this->path('/reviews/mine'))->assertOk()->assertJsonPath('can_review', false);
        $this->putJson($this->path('/reviews/mine'), ['rating' => 5])->assertForbidden();
        [$order] = $this->order();
        $this->putJson($this->path('/products/'.$this->product['id'].'/reviews/mine'), ['rating' => 4])->assertForbidden();
        DB::table('ecommerce_orders')->where('id', $order)->update(['status' => 'completed', 'platform_customer_id' => $account['customer']['id']]);
        $shopPath = $this->path('/reviews/mine');
        $productPath = $this->path('/products/'.$this->product['id'].'/reviews/mine');
        foreach ([0, 6, 2.5] as $rating) $this->putJson($shopPath, ['rating' => $rating])->assertUnprocessable();
        $this->putJson($shopPath, ['rating' => 5, 'comment' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->putJson($shopPath, ['rating' => 5, 'comment' => 'Great shop'])->assertOk()->assertJsonPath('summary.count', 1);
        $this->putJson($shopPath, ['rating' => 3, 'comment' => 'Updated'])->assertOk()->assertJsonPath('summary.count', 1)->assertJsonPath('review.rating', 3);
        $this->putJson($productPath, ['rating' => 4, 'comment' => '<script>plain text</script>'])->assertOk();
        $this->withHeader('Authorization', '');
        $unpurchased = $this->postJson('/api/products', ['name' => 'Unpurchased tea', 'price' => 1000, 'stock' => 5])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$unpurchased['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $this->putJson($this->path('/products/'.$unpurchased['id'].'/reviews/mine'), ['rating' => 5])->assertForbidden();
        $this->getJson($this->path('/reviews'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.customer_name', 'Review Buyer')
            ->assertJsonMissing(['email' => $account['customer']['email']]);
        $this->getJson($this->path(''))->assertOk()->assertJsonPath('rating.count', 1);
        $this->getJson($this->path('/products'))->assertOk()->assertJsonPath('items.0.rating.count', 1);
        $market = $this->getJson('/api/ecommerce/marketplace/products?q=Test%20rice')->assertOk()->json('items');
        $this->assertSame(1, collect($market)->firstWhere('id', $this->product['id'])['rating']['count']);
        $this->deleteJson($shopPath)->assertOk()->assertJsonPath('summary.count', 0);
        $this->getJson($this->path('/products/'.$this->product['id'].'/reviews'))->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_reviews_cannot_use_other_products_or_customers_and_owner_replies_are_tenant_scoped(): void
    {
        [$order] = $this->order();
        $account = $this->postJson('/api/ecommerce/customer/register', ['name' => 'First Buyer', 'email' => Str::uuid().'@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        DB::table('ecommerce_orders')->where('id', $order)->update(['status' => 'completed', 'platform_customer_id' => $account['customer']['id']]);
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $reviewId = $this->putJson($this->path('/reviews/mine'), ['rating' => 5])->assertOk()->json('review.id');
        $other = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Second Buyer', 'email' => Str::uuid().'@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withHeader('Authorization', 'Bearer '.$other['access_token']);
        $this->getJson($this->path('/reviews/mine'))->assertOk()->assertJsonPath('review', null);
        $this->deleteJson($this->path('/reviews/mine'))->assertOk();
        $this->assertTrue(DB::table('shop_reviews')->where('id', $reviewId)->exists());
        $this->getJson($this->path('/products/999999999/reviews'))->assertNotFound();
        $this->withHeader('Authorization', '');
        $this->actingAs(User::findOrFail($this->owner['user']['id']), 'web');
        $this->getJson('/api/shop-admin/reviews?type=shop')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/shop-admin/reviews/'.$reviewId)->assertOk()->assertJsonPath('id', $reviewId);
        $this->putJson('/api/shop-admin/reviews/'.$reviewId.'/reply', ['reply' => 'Thank you!'])->assertOk();
        $this->getJson($this->path('/reviews'))->assertOk()->assertJsonPath('items.0.reply', 'Thank you!');
        $this->putJson('/api/shop-admin/reviews/'.$reviewId.'/reply', ['reply' => ''])->assertOk()->assertJsonPath('reply', null);
        $secondOwner = $this->postJson('/api/auth/register', ['business_name' => 'Other Review Shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->actingAs(User::findOrFail($secondOwner['user']['id']), 'web');
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/reviews')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/reviews/'.$reviewId)->assertNotFound();
        $this->putJson('/api/shop-admin/reviews/'.$reviewId.'/reply', ['reply' => 'Wrong shop'])->assertNotFound();
    }

    public function test_admin_reviews_include_product_context_and_scoped_photos(): void
    {
        Storage::fake('local');
        $path = 'product-photos/review-test.png';
        Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aY1kAAAAASUVORK5CYII='));
        DB::table('products')->where('id', $this->product['id'])->update(['photo_path' => $path]);
        $customer = DB::table('platform_customers')->insertGetId(['name' => 'Photo reviewer', 'email' => Str::uuid().'@example.com', 'password' => bcrypt('password123'), 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('shop_reviews')->insertGetId(['business_id' => $this->owner['business']['id'], 'platform_customer_id' => $customer, 'target_id' => $this->product['id'], 'rating' => 5, 'comment' => 'Great product', 'created_at' => now(), 'updated_at' => now()]);
        $url = '/shop-admin/reviews/'.$id.'/photo';
        $unit = DB::table('products')->where('id', $this->product['id'])->value('base_unit');
        $this->getJson('/api/shop-admin/reviews/'.$id)->assertOk()->assertJsonPath('photo_url', $url)->assertJsonPath('product_name', $this->product['name'])->assertJsonPath('product_unit', $unit);
        $this->getJson('/api/shop-admin/reviews')->assertOk()->assertJsonPath('items.0.photo_url', $url);
        $this->get('/api'.$url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        DB::table('shop_reviews')->where('id', $id)->update(['target_id' => 0]);
        $this->get('/api'.$url)->assertNotFound();
        $other = DB::table('businesses')->insertGetId(['name' => 'Other review tenant', 'slug' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_reviews')->where('id', $id)->update(['business_id' => $other, 'target_id' => $this->product['id']]);
        $this->getJson('/api/shop-admin/reviews/'.$id)->assertNotFound();
        $this->get('/api'.$url)->assertNotFound();
    }

    public function test_reviews_paginate_compute_average_and_require_staff_permissions(): void
    {
        foreach ([3, 5] as $rating) {
            $customerId = DB::table('platform_customers')->insertGetId(['name' => 'Review customer', 'email' => Str::uuid().'@example.com',
                'password' => bcrypt('password123'), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shop_reviews')->insert(['business_id' => $this->owner['business']['id'], 'platform_customer_id' => $customerId,
                'target_id' => 0, 'rating' => $rating, 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->getJson($this->path('/reviews?limit=1'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', true)->json();
        $this->assertEquals(4, $response['summary']['average']);
        $this->assertSame(2, $response['summary']['count']);
        $this->getJson($this->path('/reviews?limit=1&offset=1'))->assertOk()->assertJsonPath('items.0.rating', 3)->assertJsonPath('has_more', false);
        $this->getJson($this->path('/products/'.$this->product['id'].'/reviews'))->assertOk()->assertJsonPath('summary.count', 0);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff']);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/reviews')->assertForbidden();
        $this->putJson('/api/shop-admin/reviews/'.$response['items'][0]['id'].'/reply', ['reply' => 'Unauthorized'])->assertForbidden();
    }

    public function test_review_filters_combine_validate_sort_and_use_inclusive_shop_dates(): void
    {
        $businessId = $this->owner['business']['id'];
        DB::table('businesses')->where('id', $businessId)->update(['timezone' => 'Asia/Yangon']);
        $rows = [
            ['target_id' => 0, 'rating' => 1, 'comment' => null, 'reply' => null, 'created_at' => '2026-10-08 23:59:59'],
            ['target_id' => $this->product['id'], 'rating' => 2, 'comment' => 'Needs improvement', 'reply' => null, 'created_at' => '2026-10-09 00:00:00'],
            ['target_id' => $this->product['id'], 'rating' => 5, 'comment' => 'Great', 'reply' => 'Thanks', 'created_at' => '2026-10-09 23:59:59'],
            ['target_id' => 0, 'rating' => 3, 'comment' => null, 'reply' => null, 'created_at' => '2026-10-10 00:00:00'],
        ];
        foreach ($rows as $i => $row) {
            $customerId = DB::table('platform_customers')->insertGetId(['name' => 'Filter buyer '.$i, 'email' => Str::uuid().'@example.com',
                'password' => bcrypt('password123'), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('shop_reviews')->insert($row + ['business_id' => $businessId, 'platform_customer_id' => $customerId, 'updated_at' => now()]);
        }
        $base = '/api/shop-admin/reviews?';
        foreach (['rating=2' => 1, 'reply_status=unreplied' => 3, 'reply_status=replied' => 1, 'content=written' => 2, 'content=rating_only' => 2,
            'type=product' => 2, 'date_from=2026-10-09&date_to=2026-10-09' => 2, 'date_to=2026-10-08' => 1, 'date_from=2026-10-10' => 1] as $filter => $count) {
            $this->getJson($base.$filter)->assertOk()->assertJsonPath('total', $count);
        }
        $this->getJson($base.'type=product&rating=2&reply_status=unreplied&content=written&date_from=2026-10-09&date_to=2026-10-09&q=improvement')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.customer_name', 'Filter buyer 1');
        foreach (['newest' => [3, 5, 2, 1], 'oldest' => [1, 2, 5, 3], 'rating_low' => [1, 2, 3, 5], 'rating_high' => [5, 3, 2, 1]] as $sort => $expected) {
            $this->assertSame($expected, array_column($this->getJson($base.'sort='.$sort)->assertOk()->json('items'), 'rating'));
        }
        $this->getJson($base.'sort=rating_low&limit=1&offset=1')->assertOk()->assertJsonPath('total', 4)->assertJsonPath('items.0.rating', 2);
        foreach (['rating=0', 'rating=6', 'reply_status=invalid', 'content=invalid', 'sort=invalid', 'date_from=bad', 'date_from=2026-10-10&date_to=2026-10-09'] as $invalid) {
            $this->getJson($base.$invalid)->assertUnprocessable();
        }
    }

    public function test_flash_sale_product_rows_have_independent_discounts_and_atomic_validation(): void
    {
        $second = $this->postJson('/api/products', ['name' => 'Test tea', 'price' => 2000, 'stock' => 10])->assertOk()->json('id');
        $this->putJson('/api/shop-admin/publications/'.$second, ['published' => true, 'unit' => 'base'])->assertOk();
        $data = ['type' => 'flash_sale', 'name' => 'Mixed discounts', 'discount_type' => 'percentage', 'discount_value' => 20,
            'enabled' => true, 'items' => [
                ['product_id' => $this->product['id'], 'discount_type' => 'percentage', 'discount_value' => 20, 'quantity_limit' => null],
                ['product_id' => $second, 'discount_type' => 'fixed', 'discount_value' => 500, 'quantity_limit' => 3.5],
            ]];
        $bad = $data; $bad['items'][1]['discount_type'] = 'percentage';
        $this->postJson('/api/shop-admin/promotions', $bad)->assertUnprocessable()->assertJsonValidationErrors('items.1.discount_value');
        $this->assertDatabaseMissing('shop_promotions', ['name' => 'Mixed discounts']);
        $bad = $data; $bad['items'][1]['enabled'] = false;
        $this->postJson('/api/shop-admin/promotions', $bad)->assertUnprocessable()->assertJsonValidationErrors('items.1');
        $bad = $data; $bad['items'][1]['product_id'] = $this->product['id'];
        $this->postJson('/api/shop-admin/promotions', $bad)->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');
        $ids = $this->postJson('/api/shop-admin/promotions', $data)->assertOk()->assertJsonCount(2, 'ids')->json('ids');
        $this->assertDatabaseHas('shop_promotions', ['id' => $ids[0], 'discount_type' => 'percentage', 'discount_value' => 20, 'quantity_limit' => null]);
        $this->assertDatabaseHas('shop_promotions', ['id' => $ids[1], 'discount_type' => 'fixed', 'discount_value' => 500, 'quantity_limit' => 3.5]);
        $this->getJson($this->path('/products'))->assertOk()->assertJsonPath('items.0.price', 800)->assertJsonPath('items.1.price', 1500);
        $this->deleteJson('/api/shop-admin/promotions/'.$ids[0])->assertOk();
        $this->postJson('/api/shop-admin/promotions', array_replace($data, ['name' => 'Rejected rows']))->assertUnprocessable();
        $this->assertDatabaseMissing('shop_promotions', ['name' => 'Rejected rows']);
    }

    public function test_flash_sale_overlap_checks_apply_on_create_update_and_enable_with_exclusive_end(): void
    {
        [$first, $data] = $this->promotion('flash_sale', ['starts_at' => '2026-10-08T09:00', 'ends_at' => '2026-10-08T10:00']);
        $this->putJson('/api/shop-admin/promotions/'.$first, $data)->assertOk(); // Ignore the row being edited.
        foreach ([['2026-10-08T09:30', '2026-10-08T11:00'], ['2026-10-08T08:00', '2026-10-08T09:30'],
            ['2026-10-08T09:15', '2026-10-08T09:45'], ['2026-10-08T08:00', '2026-10-08T11:00'], [null, null]] as [$start, $end]) {
            $this->postJson('/api/shop-admin/promotions', array_replace($data, ['starts_at' => $start, 'ends_at' => $end]))
                ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        }
        [$second, $secondData] = $this->promotion('flash_sale', ['starts_at' => '2026-10-08T10:00', 'ends_at' => '2026-10-08T11:00']);
        $this->putJson('/api/shop-admin/promotions/'.$second, array_replace($secondData, ['starts_at' => '2026-10-08T09:59']))->assertUnprocessable();
        [$paused, $pausedData] = $this->promotion('flash_sale', ['enabled' => false]);
        $this->putJson('/api/shop-admin/promotions/'.$paused, array_replace($pausedData, ['enabled' => true]))->assertUnprocessable();
        $this->deleteJson('/api/shop-admin/promotions/'.$first)->assertOk();
        $this->deleteJson('/api/shop-admin/promotions/'.$second)->assertOk();
        $this->putJson('/api/shop-admin/promotions/'.$paused, array_replace($pausedData, ['enabled' => true]))->assertOk();
        $this->postJson('/api/shop-admin/promotions', $data)->assertUnprocessable(); // Unbounded enabled event conflicts.
    }

    public function test_promotions_stack_and_discount_snapshots_match_the_completed_pos_sale(): void
    {
        [$flash] = $this->promotion('flash_sale');
        [$coupon] = $this->promotion('coupon', ['discount_value' => 10]);
        $this->getJson($this->path('/products'))->assertOk()->assertJsonPath('items.0.price', 800)->assertJsonPath('items.0.original_price', 1000)->assertJsonPath('items.0.flash_sale.remaining_quantity', null);
        $quote = $this->postJson($this->path('/quote'), $this->input(['coupon_code' => 'save20', 'fulfillment' => 'delivery', 'delivery_zone_id' => 'city', 'address' => 'City']))->assertOk()
            ->assertJsonPath('subtotal', 1600)->assertJsonPath('discount', 160)->assertJsonPath('delivery_fee', 500)->assertJsonPath('total', 1940)->json();
        $this->assertSame(160, array_sum(array_column($quote['items'], 'coupon_discount')));
        [$id, $result, $data] = $this->order(['coupon_code' => 'save20', 'fulfillment' => 'delivery', 'delivery_zone_id' => 'city', 'address' => 'City']);
        $this->postJson($this->path('/checkout'), $data)->assertOk()->assertJsonPath('reference', $result['reference']);
        $this->assertSame(2, DB::table('shop_promotion_redemptions')->where('order_id', $id)->count());
        $this->assertDatabaseHas('shop_promotions', ['id' => $flash, 'used_count' => 1, 'used_quantity' => 2]);
        $this->assertDatabaseHas('shop_promotions', ['id' => $coupon, 'used_count' => 1]);
        $this->pay($id, 1940);
        $this->transition($id, 'accepted')->assertOk();
        $this->transition($id, 'packing')->assertOk();
        $this->transition($id, 'dispatched')->assertOk();
        $this->transition($id, 'completed')->assertOk()->assertJsonPath('discount', 160)->assertJsonPath('total', 1940);
        $saleId = DB::table('ecommerce_orders')->where('id', $id)->value('sale_id');
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'subtotal' => 1600, 'discount' => 160, 'delivery_fee' => 500, 'total' => 1940, 'credit_amount' => 0]);
        $this->deleteJson('/api/shop-admin/promotions/'.$coupon)->assertOk();
        $this->getJson('/api/shop-admin/orders/'.$id)->assertOk()->assertJsonPath('promotions.1.code', 'SAVE20');
    }

    public function test_promotion_limits_and_stale_quotes_cannot_over_redeem(): void
    {
        [$flash, $flashData] = $this->promotion('flash_sale', ['quantity_limit' => 2]);
        [$coupon, $couponData] = $this->promotion('coupon', ['usage_limit' => 1]);
        $stale = $this->input();
        $stale['quote_hash'] = $this->postJson($this->path('/quote'), $stale)->assertOk()->json('quote_hash');
        $staleCoupon = $this->input(['coupon_code' => 'SAVE20']);
        $staleCoupon['quote_hash'] = $this->postJson($this->path('/quote'), $staleCoupon)->assertOk()->json('quote_hash');
        $this->postJson($this->path('/quote'), $this->input(['items' => [['product_id' => $this->product['id'], 'quantity' => 3]]]))->assertConflict();
        [$id, $result, $data] = $this->order(['coupon_code' => 'SAVE20']);
        $this->postJson($this->path('/checkout'), $stale)->assertConflict();
        $this->postJson($this->path('/checkout'), $staleCoupon)->assertUnprocessable();
        $this->postJson($this->path('/checkout'), $data)->assertOk()->assertJsonPath('reference', $result['reference']);
        $this->getJson($this->path('/products'))->assertOk()->assertJsonPath('items.0.price', 1000)->assertJsonPath('items.0.flash_sale', null);
        $this->getJson('/api/shop-admin/promotions?status=exhausted')->assertOk()->assertJsonCount(2, 'items');
        $this->putJson('/api/shop-admin/promotions/'.$flash, array_replace($flashData, ['quantity_limit' => 1]))->assertUnprocessable();
        $this->transition($id, 'cancelled', ['reason' => 'Customer cancelled'])->assertOk();
        $this->assertDatabaseHas('shop_promotions', ['id' => $flash, 'used_count' => 1, 'used_quantity' => 2]);
        $this->assertDatabaseHas('shop_promotions', ['id' => $coupon, 'used_count' => 1]);
    }

    public function test_promotion_dates_are_optional_and_use_shop_timezone_with_exclusive_end(): void
    {
        $now = now()->addMinute()->startOfMinute();
        $this->travelTo($now);
        $local = $now->copy()->timezone('Asia/Rangoon');
        [$flash, $data] = $this->promotion('flash_sale', ['starts_at' => $local->copy()->addMinutes(10)->format('Y-m-d\TH:i'), 'ends_at' => $local->copy()->addMinutes(20)->format('Y-m-d\TH:i')]);
        $this->assertDatabaseHas('shop_promotions', ['id' => $flash, 'starts_at' => $now->copy()->addMinutes(10)->utc()->format('Y-m-d H:i:s')]);
        $this->getJson('/api/shop-admin/promotions?status=scheduled')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($this->path('/products'))->assertJsonPath('items.0.price', 1000);
        $this->travelTo($now->copy()->addMinutes(10));
        $this->getJson($this->path('/products'))->assertJsonPath('items.0.price', 800);
        $stale = $this->input();
        $stale['quote_hash'] = $this->postJson($this->path('/quote'), $stale)->assertOk()->json('quote_hash');
        $this->travelTo($now->copy()->addMinutes(20));
        $this->postJson($this->path('/checkout'), $stale)->assertConflict();
        $this->getJson('/api/shop-admin/promotions?status=expired')->assertOk()->assertJsonCount(1, 'items');
        $this->putJson('/api/shop-admin/promotions/'.$flash, array_replace($data, ['starts_at' => null, 'ends_at' => null]))->assertOk();
        $this->getJson($this->path('/products'))->assertJsonPath('items.0.price', 800);
        [$coupon, $couponData] = $this->promotion('coupon', ['starts_at' => null, 'ends_at' => null, 'minimum_subtotal' => 2000]);
        $this->postJson($this->path('/quote'), $this->input(['coupon_code' => 'SAVE20']))->assertUnprocessable();
        $this->putJson('/api/shop-admin/promotions/'.$coupon, array_replace($couponData, ['starts_at' => $local->format('Y-m-d\TH:i'), 'ends_at' => $local->format('Y-m-d\TH:i')]))->assertUnprocessable();
        $this->travelBack();
    }

    public function test_mobile_date_only_promotions_accept_midnight_at_api_minute_precision(): void
    {
        foreach (['coupon', 'flash_sale'] as $type) {
            [$id, $data] = $this->promotion($type, ['starts_at' => '2026-10-01T00:00', 'ends_at' => '2026-10-31T00:00']);
            $this->assertDatabaseHas('shop_promotions', ['id' => $id,
                'starts_at' => '2026-09-30 17:30:00', 'ends_at' => '2026-10-30 17:30:00']);
            $this->putJson('/api/shop-admin/promotions/'.$id, array_replace($data,
                ['starts_at' => '2026-10-01T00:00:00', 'ends_at' => '2026-10-31T00:00:00']))
                ->assertUnprocessable()->assertJsonValidationErrors(['starts_at', 'ends_at']);
            $this->putJson('/api/shop-admin/promotions/'.$id, array_replace($data,
                ['starts_at' => null, 'ends_at' => null]))->assertOk();
            $this->assertDatabaseHas('shop_promotions', ['id' => $id, 'starts_at' => null, 'ends_at' => null]);
        }
    }

    public function test_flash_sale_picker_marks_only_schedule_conflicts_and_supports_selected_id_checks(): void
    {
        [$flash, $data] = $this->promotion('flash_sale', ['starts_at' => '2026-10-01T00:00', 'ends_at' => '2026-10-10T00:00']);
        $filters = ['publication_status' => 'published', 'flash_sale_picker' => 1,
            'flash_sale_starts_at' => '2026-10-05T00:00', 'flash_sale_ends_at' => '2026-10-06T00:00'];
        $path = fn ($extra = []) => '/api/shop-admin/publications?'.http_build_query(array_replace($filters, $extra));
        $this->getJson($path())->assertOk()->assertJsonPath('items.0.flash_sale_conflict.id', $flash);
        $this->getJson($path(['flash_sale_starts_at' => '2026-10-10T00:00', 'flash_sale_ends_at' => '2026-10-11T00:00']))
            ->assertOk()->assertJsonPath('items.0.flash_sale_conflict', null);
        $this->getJson($path(['flash_sale_exclude_id' => $flash]))->assertOk()->assertJsonPath('items.0.flash_sale_conflict', null);
        $this->getJson($path(['flash_sale_enabled' => 0]))->assertOk()->assertJsonPath('items.0.flash_sale_conflict', null);
        $this->getJson($path(['flash_sale_product_ids' => [$this->product['id']]]))->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($path(['flash_sale_product_ids' => [2147483647]]))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/shop-admin/publications')->assertOk()->assertJsonMissingPath('items.0.flash_sale_conflict');
        $this->putJson('/api/shop-admin/promotions/'.$flash, array_replace($data, ['enabled' => false]))->assertOk();
        $this->getJson($path())->assertOk()->assertJsonPath('items.0.flash_sale_conflict', null);
        $this->putJson('/api/shop-admin/promotions/'.$flash, $data)->assertOk();
        $this->deleteJson('/api/shop-admin/promotions/'.$flash)->assertOk();
        $this->getJson($path())->assertOk()->assertJsonPath('items.0.flash_sale_conflict', null);
    }

    public function test_bulk_product_deletion_preserves_history_and_rejects_reserved_or_invalid_rows_atomically(): void
    {
        $second = $this->postJson('/api/products', ['name' => 'Test tea', 'price' => 1000, 'stock' => 10])->assertOk()->json('id');
        [$orderId] = $this->order();
        $this->postJson('/api/products/bulk-delete', ['ids' => [$second, $this->product['id']]])->assertStatus(409);
        foreach ([$second, $this->product['id']] as $id) $this->assertDatabaseHas('products', ['id' => $id, 'is_active' => true]);
        $this->postJson('/api/products/bulk-delete', ['ids' => []])->assertUnprocessable();
        $this->postJson('/api/products/bulk-delete', ['ids' => [$second, $second]])->assertUnprocessable();
        $this->postJson('/api/products/bulk-delete', ['ids' => [$second, 2147483647]])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $second, 'is_active' => true]);
        $this->transition($orderId, 'cancelled', ['reason' => 'Customer cancelled'])->assertOk();
        $this->postJson('/api/products/bulk-delete', ['ids' => [$second, $this->product['id']]])->assertOk()->assertJsonPath('deleted_count', 2);
        foreach ([$second, $this->product['id']] as $id) $this->assertDatabaseHas('products', ['id' => $id, 'is_active' => false]);
        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/shop-admin/orders/'.$orderId)->assertOk()->assertJsonPath('items.0.name', $this->product['name']);
        $this->assertDatabaseHas('product_prices', ['product_id' => $this->product['id'], 'name' => 'Retail']);
        $third = $this->postJson('/api/products', ['name' => 'Still active', 'price' => 1000, 'stock' => 1])->assertOk()->json('id');
        $this->postJson('/api/products/bulk-delete', ['ids' => [$third, $second]])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $third, 'is_active' => true]);
    }

    public function test_bulk_product_deletion_requires_products_permission_and_is_tenant_scoped(): void
    {
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Viewer',
            'permissions' => json_encode(['sell']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Viewer', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->postJson('/api/products/bulk-delete', ['ids' => [$this->product['id']]])->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other product shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $other = $this->postJson('/api/products', ['name' => 'Other product', 'price' => 1000, 'stock' => 1])->assertOk()->json('id');
        $this->postJson('/api/products/bulk-delete', ['ids' => [$other, $this->product['id']]])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $other, 'is_active' => true]);
        $this->assertDatabaseHas('products', ['id' => $this->product['id'], 'is_active' => true]);
    }

    public function test_bulk_promotion_deletion_is_atomic_and_preserves_order_history(): void
    {
        [$flash] = $this->promotion('flash_sale');
        [$coupon] = $this->promotion('coupon');
        [$orderId] = $this->order(['coupon_code' => 'SAVE20']);
        $this->assertSame(2, DB::table('shop_promotion_redemptions')->where('order_id', $orderId)->count());
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => []])->assertUnprocessable();
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => [$flash, $flash]])->assertUnprocessable();
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => [$flash, 2147483647]])->assertUnprocessable();
        $this->assertDatabaseHas('shop_promotions', ['id' => $flash, 'deleted_at' => null, 'enabled' => true]);
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => [$flash, $coupon]])
            ->assertOk()->assertJsonPath('deleted_count', 2);
        foreach ([$flash, $coupon] as $id) {
            $this->assertNotNull(DB::table('shop_promotions')->where('id', $id)->value('deleted_at'));
            $this->assertDatabaseHas('shop_promotions', ['id' => $id, 'enabled' => false, 'used_count' => 1]);
        }
        $this->getJson('/api/shop-admin/promotions')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->path('/products'))->assertOk()->assertJsonPath('items.0.price', 1000);
        $this->getJson('/api/shop-admin/orders/'.$orderId)->assertOk()->assertJsonCount(2, 'promotions');
        $this->assertSame(2, DB::table('shop_promotion_redemptions')->where('order_id', $orderId)->count());
        [$new] = $this->promotion('flash_sale');
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => [$new, $flash]])->assertUnprocessable();
        $this->assertDatabaseHas('shop_promotions', ['id' => $new, 'deleted_at' => null, 'enabled' => true]);
    }

    public function test_promotions_require_catalog_permission_and_do_not_cross_tenants(): void
    {
        [$coupon] = $this->promotion('coupon');
        $this->getJson('/api/shop-admin/promotions/'.$coupon)->assertOk()->assertJsonPath('promotion.id', $coupon)->assertJsonPath('timezone', $this->owner['business']['timezone']);
        $role = DB::table('access_roles')->insertGetId(['business_id' => $this->owner['business']['id'], 'name' => 'Shop viewer',
            'permissions' => json_encode(['ecommerce_orders']), 'created_at' => now(), 'updated_at' => now()]);
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Viewer', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true, 'access_role_id' => $role]);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/promotions')->assertForbidden();
        $this->getJson('/api/shop-admin/promotions/'.$coupon)->assertForbidden();
        $this->deleteJson('/api/shop-admin/promotions/'.$coupon)->assertForbidden();
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => [$coupon]])->assertForbidden();
        $this->postJson('/api/auth/register', ['business_name' => 'Other promotion shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/settings')->assertOk();
        $this->getJson('/api/shop-admin/promotions')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/shop-admin/promotions/'.$coupon)->assertNotFound();
        $this->deleteJson('/api/shop-admin/promotions/'.$coupon)->assertNotFound();
        [$otherCoupon] = $this->promotion('coupon'); // The same code is allowed in another shop.
        $this->postJson('/api/shop-admin/promotions/bulk-delete', ['ids' => [$otherCoupon, $coupon]])->assertUnprocessable();
        $this->assertDatabaseHas('shop_promotions', ['id' => $coupon, 'deleted_at' => null]);
        $this->assertDatabaseHas('shop_promotions', ['id' => $otherCoupon, 'deleted_at' => null]);
    }

    public function test_fixed_promotions_fractional_limits_and_discount_allocation_use_exact_money(): void
    {
        [$flash] = $this->promotion('flash_sale', ['discount_type' => 'fixed', 'discount_value' => 201, 'quantity_limit' => 1.5]);
        [$coupon] = $this->promotion('coupon', ['discount_type' => 'fixed', 'discount_value' => 301, 'minimum_subtotal' => null]);
        $tea = $this->postJson('/api/products', ['name' => 'Promotion tea', 'price' => 777, 'stock' => 10, 'prices' => [['name' => 'Retail', 'price' => 777]]])->assertOk()->json('id');
        $this->putJson('/api/shop-admin/publications/'.$tea, ['published' => true, 'unit' => 'base'])->assertOk();
        [$id] = $this->order(['coupon_code' => 'SAVE20', 'items' => [['product_id' => $this->product['id'], 'quantity' => 1.5], ['product_id' => $tea, 'quantity' => 0.5]]]);
        // 799 * 1.5 rounds to 1199, 777 * .5 rounds to 389.
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $id, 'subtotal' => 1588, 'discount' => 301, 'total' => 1287]);
        $this->assertSame(301, (int) DB::table('ecommerce_order_items')->where('order_id', $id)->sum('coupon_discount'));
        $this->assertDatabaseHas('shop_promotions', ['id' => $flash, 'used_quantity' => 1.5]);
        $this->getJson($this->path('/products/'.$this->product['id']))->assertJsonPath('price', 1000);
        $this->getJson('/api/shop-admin/promotions?type=coupon&q=SAVE20&limit=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $coupon);
        $this->postJson('/api/shop-admin/promotions', ['type' => 'coupon', 'name' => 'Duplicate', 'code' => 'save20', 'discount_type' => 'fixed', 'discount_value' => 10, 'enabled' => true])->assertUnprocessable();
    }

    public function test_public_catalog_pagination_is_bounded_and_stable(): void
    {
        $this->getJson($this->path('/products?limit=999999'))->assertOk()->assertJsonPath('limit', 48);
        $this->getJson($this->path('/products?limit=1'))->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        $this->getJson($this->path('/products?limit=1&offset=1'))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('has_more', false);
    }
}
