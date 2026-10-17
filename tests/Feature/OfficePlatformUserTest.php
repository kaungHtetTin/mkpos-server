<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformCustomer;
use App\Services\PlatformCustomerSessions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficePlatformUserTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): void
    {
        $this->actingAs(PlatformAdmin::create(['name' => 'QA Office', 'email' => Str::uuid().'@example.com',
            'password' => Hash::make('password123'), 'is_active' => true]), 'office');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'QA Customer', 'email' => Str::uuid().'@example.com', 'phone' => '091234567',
            'address' => 'Yangon', 'password' => 'password123', 'password_confirmation' => 'password123', 'is_active' => true], $overrides);
    }

    public function test_all_endpoints_require_office_access(): void
    {
        $customer = PlatformCustomer::create($this->payload());
        $this->getJson('/api/office/platform-users')->assertUnauthorized();
        $this->getJson('/api/office/platform-users/'.$customer->id)->assertUnauthorized();
        $this->postJson('/api/office/platform-users', $this->payload())->assertUnauthorized();
        $this->putJson('/api/office/platform-users/'.$customer->id, $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/office/platform-users/'.$customer->id)->assertUnauthorized();
        $token = app(PlatformCustomerSessions::class)->issue((object) $customer->getAttributes())['access_token'];
        $this->withToken($token)->getJson('/api/office/platform-users')->assertUnauthorized();
    }

    public function test_create_read_update_and_customer_login(): void
    {
        $this->login();
        $data = $this->payload(['email' => '  '.Str::uuid().'@EXAMPLE.COM  ']);
        $row = $this->postJson('/api/office/platform-users', $data)->assertCreated()->assertJsonMissingPath('password')->json();
        $id = $row['id'];
        $this->assertSame(strtolower(trim($data['email'])), $row['email']);
        $this->assertTrue(Hash::check('password123', PlatformCustomer::findOrFail($id)->password));
        $this->getJson('/api/office/platform-users/'.$id)->assertOk()->assertJsonMissingPath('password')->assertJsonPath('address', 'Yangon');
        $token = $this->postJson('/api/ecommerce/customer/login', ['email' => $row['email'], 'password' => 'password123'])->assertOk()->json('access_token');
        $data['email'] = $row['email']; $data['name'] = 'Updated Customer'; $data['password'] = ''; $data['password_confirmation'] = '';
        $this->putJson('/api/office/platform-users/'.$id, $data)->assertOk()->assertJsonPath('name', 'Updated Customer');
        $this->assertNotNull(app(PlatformCustomerSessions::class)->resolve($token));
        $data['password'] = $data['password_confirmation'] = 'newpassword123';
        $this->putJson('/api/office/platform-users/'.$id, $data)->assertOk();
        $this->assertNull(app(PlatformCustomerSessions::class)->resolve($token));
        $this->postJson('/api/ecommerce/customer/login', ['email' => $row['email'], 'password' => 'password123'])->assertUnprocessable();
        $token = $this->postJson('/api/ecommerce/customer/login', ['email' => $row['email'], 'password' => 'newpassword123'])->assertOk()->json('access_token');
        $data['password'] = ''; $data['password_confirmation'] = ''; $data['is_active'] = false;
        $this->putJson('/api/office/platform-users/'.$id, $data)->assertOk()->assertJsonPath('is_active', false);
        $this->assertNull(app(PlatformCustomerSessions::class)->resolve($token));
        $this->postJson('/api/ecommerce/customer/login', ['email' => $row['email'], 'password' => 'newpassword123'])->assertUnprocessable();
    }

    public function test_validation_search_and_pagination(): void
    {
        $this->login(); $prefix = 'QA-'.Str::uuid();
        for ($i = 0; $i < 12; $i++) $this->postJson('/api/office/platform-users', $this->payload(['name' => $prefix.'-'.$i, 'is_active' => $i % 2 === 0]))->assertCreated();
        $this->getJson('/api/office/platform-users?q='.$prefix.'&per_page=10&page=999')->assertOk()->assertJsonPath('total', 12)->assertJsonPath('page', 2)->assertJsonCount(2, 'items');
        $this->getJson('/api/office/platform-users?q='.$prefix.'&status=inactive')->assertOk()->assertJsonPath('total', 6)->assertJsonPath('items.0.is_active', false)->assertJsonMissingPath('items.0.password');
        $this->getJson('/api/office/platform-users?status=invalid')->assertUnprocessable();
        $this->getJson('/api/office/platform-users?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/office/platform-users?q='.$prefix.'&per_page=100')->assertOk()->assertJsonPath('per_page', 100)->assertJsonCount(12, 'items');
        $data = $this->payload();
        $this->postJson('/api/office/platform-users', $data)->assertCreated();
        $this->postJson('/api/office/platform-users', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/office/platform-users', $this->payload(['password_confirmation' => 'mismatch', 'name' => '  ']))->assertUnprocessable()->assertJsonValidationErrors(['password', 'name']);
        $this->getJson('/api/office/platform-users/999999999')->assertNotFound();
    }

    public function test_registration_contact_filters_and_sorting(): void
    {
        $this->login(); $prefix = 'Filter-'.Str::uuid();
        foreach ([['name' => 'Zulu', 'phone' => null, 'address' => '', 'created_at' => '2026-09-01 12:00:00'],
            ['name' => 'Alpha', 'phone' => '09123', 'address' => 'Yangon', 'created_at' => '2026-10-10 23:59:59'],
            ['name' => 'Bravo', 'phone' => '', 'address' => '   ', 'created_at' => '2026-10-11 00:00:00']] as $row) {
            $customer = PlatformCustomer::create(array_merge($this->payload(), $row, ['name' => $prefix.' '.$row['name']]));
            DB::table('platform_customers')->where('id', $customer->id)->update(['created_at' => $row['created_at']]);
        }
        $base = '/api/office/platform-users?q='.$prefix;
        $this->getJson($base.'&phone=missing&address=missing')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($base.'&phone=present&address=present')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.name', $prefix.' Alpha');
        $this->getJson($base.'&registered_from=2026-10-10&registered_to=2026-10-10')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.name', $prefix.' Alpha');
        $this->getJson($base.'&registered_to=2026-09-01')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($base.'&registered_from=2026-10-11')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($base.'&sort=newest')->assertOk()->assertJsonPath('items.0.name', $prefix.' Bravo');
        $this->getJson($base.'&sort=oldest')->assertOk()->assertJsonPath('items.0.name', $prefix.' Zulu');
        $this->getJson($base.'&sort=name')->assertOk()->assertJsonPath('items.0.name', $prefix.' Alpha');
        $this->getJson($base.'&phone=invalid&sort=invalid')->assertUnprocessable()->assertJsonValidationErrors(['phone', 'sort']);
        $this->getJson($base.'&registered_from=2026-10-12&registered_to=2026-10-10')->assertUnprocessable()->assertJsonValidationErrors('registered_to');
        $this->getJson($base.'&registered_from=invalid')->assertUnprocessable()->assertJsonValidationErrors('registered_from');
    }

    public function test_delete_revokes_sessions_cascades_saved_addresses_and_preserves_orders(): void
    {
        $this->login();
        $id = $this->postJson('/api/office/platform-users', $this->payload())->assertCreated()->json('id');
        $customer = PlatformCustomer::findOrFail($id);
        $token = app(PlatformCustomerSessions::class)->issue((object) $customer->getAttributes())['access_token'];
        DB::table('shop_customer_addresses')->insert(['customer_id' => $id, 'label' => 'Home', 'recipient' => 'QA', 'phone' => '123', 'address' => 'Yangon']);
        $businessId = DB::table('businesses')->insertGetId(['name' => 'QA', 'slug' => Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $orderId = DB::table('ecommerce_orders')->insertGetId(['business_id' => $businessId, 'platform_customer_id' => $id,
            'reference' => Str::uuid(), 'origin' => 'storefront', 'customer_name' => 'Saved Customer', 'phone' => '123',
            'fulfillment' => 'pickup', 'payment_method' => '{}', 'currency' => 'Ks', 'subtotal' => 100, 'total' => 100,
            'tracking_hash' => hash('sha256', 'qa'), 'idempotency_key' => Str::uuid(), 'request_hash' => hash('sha256', 'qa')]);
        $this->deleteJson('/api/office/platform-users/'.$id)->assertOk();
        $this->assertDatabaseMissing('platform_customers', ['id' => $id]);
        $this->assertDatabaseMissing('shop_customer_addresses', ['customer_id' => $id]);
        $this->assertNull(app(PlatformCustomerSessions::class)->resolve($token));
        $this->assertDatabaseHas('ecommerce_orders', ['id' => $orderId, 'platform_customer_id' => null, 'customer_name' => 'Saved Customer']);
        $this->deleteJson('/api/office/platform-users/'.$id)->assertNotFound();
    }
}
