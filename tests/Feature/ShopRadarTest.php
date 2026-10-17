<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopRadarTest extends TestCase
{
    use DatabaseTransactions;

    private function shop(string $name, float $latitude, float $longitude): array
    {
        $owner = $this->postJson('/api/auth/register', ['business_name' => $name, 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => 10])->assertOk();
        $settings = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->putJson('/api/shop-admin/settings', array_merge($settings, ['storefront_enabled' => true, 'marketplace_enabled' => true, 'accepting_orders' => true]))->assertOk();
        return ['id' => $owner['business']['id'], 'slug' => $settings['slug']];
    }

    private function radar(array $extra = [])
    {
        return $this->getJson('/api/ecommerce/marketplace/radar?'.http_build_query($extra + ['latitude' => 16.84, 'longitude' => 96.17, 'radius_km' => 5]));
    }

    public function test_nearby_shops_are_nearest_first_radius_filtered_and_paginated_before_response(): void
    {
        $first = $this->shop('Radar QA First', 16.84, 96.17);
        $second = $this->shop('Radar QA Second', 16.85, 96.17);
        $this->shop('Radar QA Far', 17, 96.17);
        $this->radar(['q' => 'Radar QA'])->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.slug', $first['slug'])->assertJsonPath('items.0.distance_km', 0)
            ->assertJsonPath('items.1.slug', $second['slug'])->assertJsonPath('has_more', false);
        $this->radar(['q' => 'Radar QA', 'limit' => 1])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', true);
        $this->radar(['q' => 'Radar QA', 'limit' => 1, 'offset' => 1])->assertOk()->assertJsonPath('items.0.slug', $second['slug'])->assertJsonPath('has_more', false);
        $this->radar(['q' => 'Radar QA', 'radius_km' => 1])->assertOk()->assertJsonCount(1, 'items');
        $item = $this->radar(['q' => 'Radar QA First'])->assertOk()->json('items.0');
        $this->assertArrayNotHasKey('owner_id', $item); $this->assertArrayNotHasKey('accuracy', $item['location']);
        $this->assertEquals(16.84, $item['location']['latitude']);
    }

    public function test_private_ineligible_and_unlocated_shops_are_excluded_but_temporarily_closed_shops_remain_visible(): void
    {
        $shop = $this->shop('Radar private QA', 16.84, 96.17);
        foreach (['marketplace_suspended' => true, 'marketplace_enabled' => false, 'storefront_enabled' => false] as $field => $value) {
            DB::table('storefront_settings')->where('business_id', $shop['id'])->update([$field => $value]);
            $this->radar(['q' => 'Radar private QA'])->assertOk()->assertJsonCount(0, 'items');
            DB::table('storefront_settings')->where('business_id', $shop['id'])->update([$field => !$value]);
        }
        DB::table('storefront_settings')->where('business_id', $shop['id'])->update(['accepting_orders' => false]);
        $this->radar(['q' => 'Radar private QA'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.accepting_orders', false);
        DB::table('businesses')->where('id', $shop['id'])->update(['status' => 'suspended']);
        $this->radar(['q' => 'Radar private QA'])->assertOk()->assertJsonCount(0, 'items');
        DB::table('businesses')->where('id', $shop['id'])->update(['status' => 'active']);
        DB::table('business_subscriptions')->where('business_id', $shop['id'])->update(['ends_at' => now()->subDay()]);
        $this->radar(['q' => 'Radar private QA'])->assertOk()->assertJsonCount(0, 'items');
        DB::table('business_subscriptions')->where('business_id', $shop['id'])->update(['ends_at' => now()->addDay()]);
        DB::table('shop_owner_locations')->where('business_id', $shop['id'])->delete();
        $this->radar(['q' => 'Radar private QA'])->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_date_line_and_coordinate_validation(): void
    {
        $shop = $this->shop('Radar date line QA', 0, -179.99);
        $this->radar(['latitude' => 0, 'longitude' => 179.99, 'q' => 'Radar date line QA'])->assertOk()->assertJsonPath('items.0.slug', $shop['slug']);
        $this->getJson('/api/ecommerce/marketplace/radar')->assertUnprocessable();
        foreach ([['latitude' => 91], ['longitude' => -181], ['latitude' => 'invalid'], ['radius_km' => 51], ['radius_km' => 0], ['limit' => 100], ['offset' => -1]] as $extra) $this->radar($extra)->assertUnprocessable();
    }
}
