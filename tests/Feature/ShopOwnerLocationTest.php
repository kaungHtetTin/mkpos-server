<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopOwnerLocationTest extends TestCase
{
    use DatabaseTransactions;
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost');
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'GPS shop', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
    }

    public function test_owner_location_is_required_before_all_shop_operations(): void
    {
        $this->getJson('/api/shop-admin/owner-location')->assertOk()->assertJsonPath('submitted', false);
        foreach (['settings', 'orders', 'report', 'publications', 'payment-methods', 'delivery-zones'] as $path) {
            $this->getJson('/api/shop-admin/'.$path)->assertForbidden()->assertJsonPath('code', 'shop_location_required');
        }
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 91, 'longitude' => 181, 'accuracy' => -1])->assertUnprocessable();
        $this->getJson('/api/shop-admin/orders')->assertForbidden();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.8409, 'longitude' => 96.1735, 'accuracy' => 25])->assertOk();
        $this->getJson('/api/shop-admin/owner-location')->assertOk()->assertJsonPath('submitted', true)
            ->assertJsonPath('location.latitude', 16.8409)->assertJsonPath('location.longitude', 96.1735);
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonMissingPath('latitude')->assertJsonMissingPath('longitude');
        $this->getJson('/api/shop-admin/orders')->assertOk();
        $this->assertDatabaseHas('shop_owner_locations', ['business_id' => $this->owner['business']['id'], 'owner_id' => $this->owner['user']['id']]);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 17, 'longitude' => 97, 'accuracy' => 10])->assertOk();
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('location.latitude', 17)->assertJsonPath('location.longitude', 97);
        $this->assertSame(1, DB::table('shop_owner_locations')->where('business_id', $this->owner['business']['id'])->count());
    }

    public function test_staff_cannot_submit_location_and_other_businesses_cannot_inherit_it(): void
    {
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password123'), 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($staff, 'web');
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16, 'longitude' => 96, 'accuracy' => 10])->assertForbidden();
        $this->getJson('/api/shop-admin/owner-location')->assertJsonPath('submitted', false);
        $this->postJson('/api/auth/register', ['business_name' => 'Other GPS shop', 'owner_name' => 'Other',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 15, 'longitude' => 95, 'accuracy' => 10])->assertOk();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/shop-admin/owner-location')->assertJsonPath('submitted', false);
        $this->getJson('/api/shop-admin/orders')->assertForbidden()->assertJsonPath('code', 'shop_location_required');
    }
}
