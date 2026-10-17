<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantMarketplaceCategoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_optional_product_category_links_are_atomic_versioned_and_audited(): void
    {
        $this->withHeaders(['Origin' => 'http://127.0.0.1:5176', 'Referer' => 'http://127.0.0.1:5176/products']);
        $session = $this->postJson('/api/auth/register', ['business_name' => 'Category Shop', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Category Plan', 'slug' => Str::uuid(), 'price' => 1000,
            'currency' => 'Ks', 'duration_days' => 30, 'features' => '[]', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_subscriptions')->insert(['business_id' => $session['business']['id'], 'subscription_plan_id' => $plan,
            'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30), 'price_paid' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $parent = DB::table('marketplace_categories')->insertGetId(['name_en' => 'Food fixture', 'slug' => Str::uuid(), 'is_active' => true, 'sort_order' => 0, 'created_by_admin_id' => 0, 'updated_by_admin_id' => 0]);
        $child = DB::table('marketplace_categories')->insertGetId(['name_en' => 'Rice fixture', 'slug' => Str::uuid(), 'parent_id' => $parent, 'is_active' => true, 'sort_order' => 0, 'created_by_admin_id' => 0, 'updated_by_admin_id' => 0]);
        $options = $this->getJson('/api/products/marketplace-categories')->assertOk()->json('items');
        $this->assertSame('Food fixture / Rice fixture', collect($options)->firstWhere('id', $child)['path']);
        $plain = $this->postJson('/api/products', ['name' => 'Optional fixture', 'price' => 1000])->assertOk()->json();
        $this->assertNull($plain['marketplace_category_id']);
        $this->assertDatabaseMissing('marketplace_product_categories', ['product_id' => $plain['id']]);
        $payload = ['name' => 'Linked fixture', 'category' => 'Local shelf', 'price' => 1000,
            'marketplace_category_id' => $child, 'marketplace_category_version' => 0];
        $product = $this->postJson('/api/products', $payload)->assertOk()->assertJsonPath('marketplace_category.path', 'Food fixture / Rice fixture')->json();
        $id = $product['id'];
        $this->assertDatabaseHas('marketplace_product_category_audits', ['product_id' => $id, 'user_id' => $session['user']['id'], 'admin_id' => null]);
        $this->putJson('/api/products/'.$id, ['name' => 'Other edit', 'price' => 1500])->assertOk()->assertJsonPath('marketplace_category_id', $child);
        $this->putJson('/api/products/'.$id, $payload + [])->assertUnprocessable()->assertJsonValidationErrors('marketplace_category_id');
        $this->getJson('/api/products/'.$id)->assertJsonPath('name', 'Other edit');
        DB::table('marketplace_categories')->where('id', $parent)->update(['is_active' => false]);
        $this->assertFalse(collect($this->getJson('/api/products/marketplace-categories')->json('items'))->contains('id', $child));
        $this->putJson('/api/products/'.$id, ['name' => 'Keep inactive', 'price' => 1500])->assertOk()->assertJsonPath('marketplace_category_id', $child);
        $this->postJson('/api/products', array_replace($payload, ['name' => 'Rejected inactive']))->assertUnprocessable()->assertJsonValidationErrors('marketplace_category_id');
        $this->assertDatabaseMissing('products', ['name' => 'Rejected inactive']);
        $this->putJson('/api/products/'.$id, ['name' => 'Cleared', 'price' => 1500, 'marketplace_category_id' => null,
            'marketplace_category_version' => 1])->assertOk()->assertJsonPath('marketplace_category_id', null)->assertJsonPath('marketplace_category_version', 2);
        $this->postJson('/api/products', array_replace($payload, ['marketplace_category_id' => 999999999]))->assertUnprocessable()->assertJsonValidationErrors('marketplace_category_id');
        $this->getJson('/api/products/'.$id)->assertJsonPath('category', '');
        $this->putJson('/api/products/'.$id, ['name' => 'Missing version', 'price' => 1000, 'marketplace_category_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('marketplace_category_version');
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other category tenant', 'slug' => Str::uuid()]);
        DB::insert('INSERT INTO products (business_id, name, price, cost, base_cost, stock, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$otherBusiness, 'Other tenant product', 1000, 0, 0, 0, true]);
        $otherProduct = (int) DB::getPdo()->lastInsertId();
        $this->getJson('/api/products/'.$otherProduct)->assertNotFound();
        $this->putJson('/api/products/'.$otherProduct, ['name' => 'Forbidden', 'price' => 1000, 'marketplace_category_id' => null,
            'marketplace_category_version' => 0])->assertNotFound();
        $this->assertDatabaseMissing('marketplace_product_categories', ['product_id' => $otherProduct]);
        $admin = \App\Models\PlatformAdmin::create(['name' => 'Category reviewer', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password123'), 'is_active' => true]);
        $this->actingAs($admin, 'office')->getJson('/api/office/marketplace-products/'.$id)->assertOk()->assertJsonPath('history.0.user_name', 'Owner');
    }
}
