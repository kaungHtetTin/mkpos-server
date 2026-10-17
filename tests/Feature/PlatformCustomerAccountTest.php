<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformCustomerAccountTest extends TestCase
{
    use DatabaseTransactions;
    private string $staffToken;
    private array $owner;
    private array $store;
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Account Test Shop', 'owner_name' => 'Owner',
            'email' => 'staff-'.Str::uuid().'@example.test', 'password' => 'staff-password123', 'password_confirmation' => 'staff-password123'])->assertCreated()->json();
        $this->staffToken = $this->owner['access_token'];
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->putJson('/api/shop-admin/settings', array_replace($this->store, ['storefront_enabled' => true, 'accepting_orders' => true, 'marketplace_enabled' => true]))->assertOk();
        $this->product = $this->postJson('/api/products', ['name' => 'Account sample', 'price' => 1000, 'stock' => 100])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $this->withHeader('Authorization', '');
    }

    private function account(?string $email = null): array
    {
        $response = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => $email ?: 'buyer-'.Str::uuid().'@example.test',
            'password' => 'customer-password123', 'password_confirmation' => 'customer-password123'])->assertOk();
        $response->assertJsonMissingPath('customer.password')->assertJsonMissingPath('customer.business_id');
        return $response->json();
    }

    private function order(?string $token = null, array $extra = []): array
    {
        $this->withHeader('Authorization', $token ? 'Bearer '.$token : '');
        $input = array_replace(['items' => [['product_id' => $this->product['id'], 'quantity' => 1]], 'fulfillment' => 'pickup',
            'payment_method_id' => 'cash', 'customer_name' => 'Buyer', 'phone' => '0912345678', 'email' => 'same-contact@example.test',
            'idempotency_key' => (string) Str::uuid()], $extra);
        $path = '/api/ecommerce/stores/'.$this->store['slug'];
        $input['quote_hash'] = $this->postJson($path.'/quote', $input)->assertOk()->json('quote_hash');
        $result = $this->postJson($path.'/checkout', $input)->assertOk()->json();
        return [$result, $input, $path];
    }

    public function test_order_history_filters_before_pagination_and_returns_scoped_item_previews(): void
    {
        $account = $this->account();
        [$pending] = $this->order($account['access_token']);
        [$completed] = $this->order($account['access_token']);
        [$cancelled] = $this->order($account['access_token']);
        DB::table('ecommerce_orders')->where('reference', $completed['reference'])->update(['status' => 'completed']);
        DB::table('ecommerce_orders')->where('reference', $cancelled['reference'])->update(['status' => 'cancelled']);
        $other = $this->account();
        $this->order($other['access_token']);
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $this->getJson('/api/ecommerce/customer/orders?status=processing&limit=1')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $pending['reference'])
            ->assertJsonPath('items.0.item_count', 1)->assertJsonPath('items.0.item_previews.0.name', 'Account sample')
            ->assertJsonMissingPath('items.0.business_id')->assertJsonPath('has_more', false);
        $this->getJson('/api/ecommerce/customer/orders?status=completed')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $completed['reference']);
        $this->getJson('/api/ecommerce/customer/orders?status=cancelled')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $cancelled['reference']);
        $this->getJson('/api/ecommerce/customer/orders?status=all&limit=1')->assertOk()->assertJsonPath('has_more', true);
        $this->getJson('/api/ecommerce/customer/orders?status=unknown')->assertUnprocessable();
    }

    public function test_profile_picture_is_compressed_replaced_and_removed_for_only_its_owner(): void
    {
        Storage::fake('local');
        $account = $this->account();
        $other = $this->account();
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $first = $this->post('/api/ecommerce/customer/profile/avatar', ['image' => UploadedFile::fake()->image('portrait.png', 900, 700), 'name' => 'Do not overwrite'])
            ->assertOk()->assertJsonPath('customer.name', 'Buyer')->assertJsonMissingPath('customer.avatar_path')->json('customer.avatar_url');
        $path = DB::table('platform_customers')->where('id', $account['customer']['id'])->value('avatar_path');
        Storage::disk('local')->assertExists($path);
        $bytes = Storage::disk('local')->get($path);
        $info = getimagesizefromstring($bytes);
        $this->assertSame([512, 512], [$info[0], $info[1]]);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(500 * 1024, strlen($bytes));
        $this->assertNull(DB::table('platform_customers')->where('id', $other['customer']['id'])->value('avatar_path'));
        $this->withHeader('Authorization', '');
        $this->get(parse_url($first, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $second = $this->post('/api/ecommerce/customer/profile/avatar', ['image' => UploadedFile::fake()->image('new.jpg')])->assertOk()->json('customer.avatar_url');
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($path);
        $newPath = DB::table('platform_customers')->where('id', $account['customer']['id'])->value('avatar_path');
        $this->deleteJson('/api/ecommerce/customer/profile/avatar')->assertOk()->assertJsonPath('customer.avatar_url', null);
        Storage::disk('local')->assertMissing($newPath);
        $this->get(parse_url($second, PHP_URL_PATH))->assertNotFound();
    }

    public function test_profile_picture_requires_customer_auth_and_rejects_invalid_uploads(): void
    {
        Storage::fake('local');
        $this->deleteJson('/api/ecommerce/customer/profile/avatar')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $this->post('/api/ecommerce/customer/profile/avatar', ['image' => UploadedFile::fake()->image('photo.jpg')])->assertUnauthorized();
        $this->withHeader('Authorization', '');
        $account = $this->account();
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $this->post('/api/ecommerce/customer/profile/avatar', ['image' => UploadedFile::fake()->create('not-image.txt', 10, 'text/plain')])->assertUnprocessable();
        $this->post('/api/ecommerce/customer/profile/avatar', ['image' => UploadedFile::fake()->image('large.jpg')->size(2049)])->assertUnprocessable();
        $this->assertNull(DB::table('platform_customers')->where('id', $account['customer']['id'])->value('avatar_path'));
        $this->assertEmpty(Storage::disk('local')->allFiles('platform-customer-avatars'));
    }

    public function test_shop_block_follows_platform_account_even_when_checkout_phone_changes(): void
    {
        $account = $this->account();
        [$order, $input, $path] = $this->order($account['access_token']);
        DB::table('ecommerce_orders')->where('reference', $order['reference'])->update(['status' => 'completed']);
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $key = 'account:'.$account['customer']['id'];
        $this->putJson('/api/shop-admin/customers/'.$key.'/block', ['blocked' => true])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        $input['phone'] = '0999999999';
        $input['idempotency_key'] = (string) Str::uuid();
        $input['quote_hash'] = $this->postJson($path.'/quote', $input)->assertOk()->json('quote_hash');
        $this->postJson($path.'/checkout', $input)->assertForbidden();
        $this->getJson('/api/ecommerce/customer/me')->assertOk();
    }

    public function test_customer_accounts_are_separate_from_business_customers_and_staff(): void
    {
        $businesses = DB::table('businesses')->count();
        $users = DB::table('users')->count();
        $customers = DB::table('customers')->count();
        $account = $this->account(strtoupper($this->owner['user']['email']));
        $this->assertSame(strtolower($this->owner['user']['email']), $account['customer']['email']);
        $this->assertSame($businesses, DB::table('businesses')->count());
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($customers, DB::table('customers')->count());
        $row = DB::table('platform_customers')->where('id', $account['customer']['id'])->first();
        $this->assertTrue(Hash::check('customer-password123', $row->password));
        $this->assertDatabaseHas('platform_customer_tokens', ['token_hash' => hash('sha256', $account['access_token'])]);
        $this->postJson('/api/ecommerce/customer/login', ['email' => $row->email, 'password' => 'staff-password123'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $row->email, 'password' => 'customer-password123'])->assertUnprocessable();
        $this->postJson('/api/ecommerce/customer/login', ['email' => strtoupper($row->email), 'password' => 'customer-password123'])
            ->assertOk()->assertJsonPath('customer.id', $row->id);
    }

    public function test_tokens_cannot_cross_between_staff_and_customer_realms(): void
    {
        $account = $this->account();
        $this->withHeader('Authorization', 'Bearer '.$account['access_token']);
        foreach (['/api/auth/me', '/api/customers', '/api/products', '/api/shop-admin/orders', '/api/office/auth/me'] as $path) $this->getJson($path)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $this->getJson('/api/ecommerce/customer/me')->assertUnauthorized();
        $this->getJson('/api/ecommerce/customer/orders')->assertUnauthorized();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/ecommerce/customer/me')->assertUnauthorized();
    }

    public function test_profile_logout_and_expired_sessions_are_customer_scoped(): void
    {
        $first = $this->account(); $second = $this->account();
        $this->withHeader('Authorization', 'Bearer '.$first['access_token']);
        $this->putJson('/api/ecommerce/customer/profile', ['name' => 'Updated buyer', 'phone' => '091111111', 'address' => 'Local address',
            'id' => $second['customer']['id'], 'email' => $second['customer']['email']])->assertOk()->assertJsonPath('customer.email', $first['customer']['email']);
        $this->assertDatabaseHas('platform_customers', ['id' => $second['customer']['id'], 'name' => 'Buyer', 'address' => null]);
        $this->postJson('/api/ecommerce/customer/logout')->assertOk();
        $this->getJson('/api/ecommerce/customer/me')->assertUnauthorized();
        DB::table('platform_customer_tokens')->where('token_hash', hash('sha256', $second['access_token']))->update(['expires_at' => now()->subMinute()]);
        $this->withHeader('Authorization', 'Bearer '.$second['access_token']);
        $this->getJson('/api/ecommerce/customer/me')->assertUnauthorized();
    }

    public function test_guest_and_account_orders_remain_separate_and_cannot_be_claimed_by_contact_details(): void
    {
        $account = $this->account(); $other = $this->account();
        [$guest] = $this->order();
        [$mine, $input, $path] = $this->order($account['access_token']);
        $this->assertDatabaseHas('ecommerce_orders', ['reference' => $guest['reference'], 'platform_customer_id' => null]);
        $this->assertDatabaseHas('ecommerce_orders', ['reference' => $mine['reference'], 'platform_customer_id' => $account['customer']['id']]);
        $this->getJson('/api/ecommerce/customer/orders')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $mine['reference']);
        $this->getJson('/api/ecommerce/customer/orders/'.$mine['reference'])->assertOk()->assertJsonPath('tracking_credential', $mine['tracking_credential']);
        $this->getJson('/api/ecommerce/customer/orders/'.$guest['reference'])->assertNotFound();
        $this->postJson($path.'/checkout', $input)->assertOk()->assertJsonPath('reference', $mine['reference']);
        $this->withHeader('Authorization', 'Bearer '.$other['access_token']);
        $this->getJson('/api/ecommerce/customer/orders')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/customer/orders/'.$mine['reference'])->assertNotFound();
        $this->postJson($path.'/checkout', $input)->assertStatus(409);
        $this->withHeader('Authorization', '');
        $this->postJson($path.'/checkout', $input)->assertStatus(409);
        $this->postJson('/api/ecommerce/tracking', ['reference' => $guest['reference'], 'credential' => $guest['tracking_credential']])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $this->getJson('/api/customers?with_total=1')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_guest_cannot_supply_an_account_id_and_invalid_auth_does_not_fall_back_to_guest(): void
    {
        $account = $this->account();
        [$guest, $input, $path] = $this->order(null, ['platform_customer_id' => $account['customer']['id']]);
        $this->assertDatabaseHas('ecommerce_orders', ['reference' => $guest['reference'], 'platform_customer_id' => null]);
        $this->withHeader('Authorization', 'Bearer pc_invalid');
        $this->postJson($path.'/checkout', $input)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $this->postJson($path.'/checkout', $input)->assertUnauthorized();
    }

    public function test_one_customer_account_can_order_across_shops_without_sharing_business_customer_lists(): void
    {
        $account = $this->account();
        [$first] = $this->order($account['access_token']);
        $this->withHeader('Authorization', '');
        $otherOwner = $this->postJson('/api/auth/register', ['business_name' => 'Other Account Shop', 'owner_name' => 'Other owner',
            'email' => 'other-shop-'.Str::uuid().'@example.test', 'password' => 'staff-password123', 'password_confirmation' => 'staff-password123'])->assertCreated()->json();
        $this->withHeader('Authorization', 'Bearer '.$otherOwner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 10])->assertOk();
        $this->putJson('/api/shop-admin/settings', array_replace($this->store, ['storefront_enabled' => true, 'accepting_orders' => true]))->assertOk();
        $this->product = $this->postJson('/api/products', ['name' => 'Other shop sample', 'price' => 2000, 'stock' => 10])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        [$second] = $this->order($account['access_token']);
        $this->getJson('/api/ecommerce/customer/orders')->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.reference', $second['reference'])->assertJsonPath('items.1.reference', $first['reference']);
        $this->withHeader('Authorization', 'Bearer '.$this->staffToken);
        $this->getJson('/api/shop-admin/orders')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $first['reference']);
        $this->getJson('/api/customers?with_total=1')->assertOk()->assertJsonCount(0, 'items');
        $this->withHeader('Authorization', 'Bearer '.$otherOwner['access_token']);
        $this->getJson('/api/shop-admin/orders')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.reference', $second['reference']);
        $this->getJson('/api/customers?with_total=1')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_order_history_is_bounded_and_remains_available_when_a_shop_closes(): void
    {
        $account = $this->account();
        [$first] = $this->order($account['access_token']); [$second] = $this->order($account['access_token']);
        DB::table('storefront_settings')->where('business_id', $this->owner['business']['id'])->update(['storefront_enabled' => false]);
        $this->getJson('/api/ecommerce/customer/orders?limit=1')->assertOk()->assertJsonPath('items.0.reference', $second['reference'])->assertJsonPath('has_more', true);
        $this->getJson('/api/ecommerce/customer/orders?limit=1&offset=1')->assertOk()->assertJsonPath('items.0.reference', $first['reference'])->assertJsonPath('has_more', false);
        $this->getJson('/api/ecommerce/customer/orders/'.$first['reference'])->assertOk();
    }
}
