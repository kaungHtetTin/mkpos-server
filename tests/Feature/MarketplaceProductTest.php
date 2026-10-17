<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceProductTest extends TestCase
{
    use DatabaseTransactions;
    private string $url = '/api/office/marketplace-products';
    private int $business;
    private int $otherBusiness;
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PlatformAdmin::create(['name' => 'Catalog staff', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'is_active' => true]);
        $this->business = DB::table('businesses')->insertGetId(['name' => 'Market Shop '.Str::uuid(), 'slug' => Str::uuid()]);
        $this->otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other Shop '.Str::uuid(), 'slug' => Str::uuid()]);
    }

    private function login(): void { $this->actingAs($this->admin, 'office'); }
    private function product(?int $business = null, array $values = []): int
    {
        return DB::table('products')->insertGetId($values + ['business_id' => $business ?? $this->business, 'name' => 'Rice '.Str::uuid(),
            'category' => 'My groceries', 'price' => 2500, 'cost' => 800, 'base_cost' => 800, 'stock' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function category(array $overrides = []): array
    {
        return $this->postJson('/api/office/marketplace-categories', $overrides + ['name_en' => 'Food', 'slug' => 'food-'.Str::uuid(), 'is_active' => true, 'sort_order' => 0])->assertCreated()->json();
    }
    private function link(array $products, ?int $category)
    {
        return $this->postJson($this->url.'/link', ['products' => $products, 'category_id' => $category]);
    }

    public function test_every_product_management_endpoint_requires_office_authentication(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
        $this->getJson($this->url.'/shops')->assertUnauthorized();
        $this->getJson($this->url.'/1')->assertUnauthorized();
        $this->link([['id' => 1, 'version' => 0]], null)->assertUnauthorized();
    }

    public function test_link_reassign_unlink_and_relink_preserve_business_product_data_and_audit_history(): void
    {
        $this->login(); $id = $this->product(); $category = $this->category(); $second = $this->category(['name_en' => 'Other']);
        $before = DB::table('products')->where('id', $id)->first();
        $this->link([['id' => $id, 'version' => 0]], $category['id'])->assertOk()->assertJsonPath('changed', 1)->assertJsonPath('items.0.link_version', 1);
        $this->link([['id' => $id, 'version' => 1]], $category['id'])->assertOk()->assertJsonPath('changed', 0);
        $this->link([['id' => $id, 'version' => 1]], $second['id'])->assertOk()->assertJsonPath('items.0.link_version', 2);
        $this->link([['id' => $id, 'version' => 2]], null)->assertOk()->assertJsonPath('items.0.link_status', 'unlinked')->assertJsonPath('items.0.link_version', 3);
        $this->link([['id' => $id, 'version' => 0]], $category['id'])->assertConflict();
        $this->link([['id' => $id, 'version' => 3]], $category['id'])->assertOk()->assertJsonPath('items.0.link_version', 4);
        $this->assertEquals($before, DB::table('products')->where('id', $id)->first());
        $detail = $this->getJson($this->url.'/'.$id)->assertOk()->assertJsonPath('marketplace_category.id', $category['id'])->assertJsonCount(4, 'history')->json();
        $this->assertArrayNotHasKey('cost', $detail); $this->assertArrayNotHasKey('base_cost', $detail);
        $this->assertEquals('Catalog staff', $detail['history'][0]['admin_name']);
        $this->assertDatabaseHas('marketplace_product_category_audits', ['product_id' => $id, 'business_id' => $this->business, 'admin_id' => $this->admin->id]);
    }

    public function test_bulk_linking_is_atomic_validated_and_derives_each_products_business(): void
    {
        $this->login(); $a = $this->product(); $b = $this->product($this->otherBusiness); $category = $this->category();
        $this->link([['id' => $a, 'version' => 0], ['id' => $b, 'version' => 1]], $category['id'])->assertConflict();
        $this->assertDatabaseMissing('marketplace_product_categories', ['product_id' => $a]);
        $this->link([['id' => $a, 'version' => 0], ['id' => 999999999, 'version' => 0]], $category['id'])->assertConflict();
        $this->assertDatabaseMissing('marketplace_product_categories', ['product_id' => $a]);
        $this->link([['id' => $a, 'version' => 0], ['id' => $a, 'version' => 0]], $category['id'])->assertUnprocessable();
        $this->link([['id' => $a, 'version' => 0], ['id' => $b, 'version' => 0]], $category['id'])->assertOk()->assertJsonPath('changed', 2);
        $this->assertDatabaseHas('marketplace_product_categories', ['product_id' => $a, 'business_id' => $this->business]);
        $this->assertDatabaseHas('marketplace_product_categories', ['product_id' => $b, 'business_id' => $this->otherBusiness]);
        $this->link([['id' => $a, 'version' => 1]], 999999999)->assertUnprocessable();
        $this->link([['id' => $a]], $category['id'])->assertUnprocessable();
        $this->postJson($this->url.'/link', ['products' => [['id' => $a, 'version' => 1]]])->assertUnprocessable();
        $this->getJson($this->url.'?per_page=999')->assertUnprocessable();
    }

    public function test_inactive_ancestors_require_review_and_linked_categories_cannot_be_deleted(): void
    {
        $this->login(); $id = $this->product(); $root = $this->category(); $child = $this->category(['name_en' => 'Rice', 'parent_id' => $root['id']]);
        $this->link([['id' => $id, 'version' => 0]], $child['id'])->assertOk();
        $this->deleteJson('/api/office/marketplace-categories/'.$child['id'], ['version' => 1])->assertConflict();
        $this->putJson('/api/office/marketplace-categories/'.$root['id'], array_merge($root, ['is_active' => false]))->assertOk();
        $this->getJson($this->url.'/'.$id)->assertOk()->assertJsonPath('link_status', 'needs_review');
        $this->link([['id' => $id, 'version' => 1]], $child['id'])->assertUnprocessable();
        $this->getJson($this->url.'?business_id='.$this->business.'&link_status=needs_review')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.needs_review', 1);
        $this->link([['id' => $id, 'version' => 1]], null)->assertOk();
        $this->deleteJson('/api/office/marketplace-categories/'.$child['id'], ['version' => 1])->assertOk();
    }

    public function test_search_shop_category_publication_and_pagination_filters(): void
    {
        $this->login(); $root = $this->category(); $child = $this->category(['parent_id' => $root['id']]);
        $id = $this->product(null, ['name' => 'Special rice', 'sku' => 'QA-SKU', 'barcode' => 'QA-BAR']);
        $other = $this->product($this->otherBusiness, ['name' => 'Special rice']);
        $this->link([['id' => $id, 'version' => 0]], $child['id'])->assertOk();
        DB::table('ecommerce_product_settings')->insert(['business_id' => $this->business, 'product_id' => $id, 'published' => true, 'unit' => 'base', 'description' => '', 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < 24; $i++) $this->product();
        $this->getJson($this->url.'?business_id='.$this->business.'&per_page=10&page=99')->assertOk()->assertJsonPath('page', 3)->assertJsonPath('total', 25)->assertJsonCount(5, 'items');
        $this->getJson($this->url.'?q=QA-BAR')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $id);
        $this->getJson($this->url.'?category_id='.$root['id'])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $id);
        $this->getJson($this->url.'?business_id='.$this->business.'&publication=published')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url.'?business_id='.$this->business.'&business_category=My%20groceries&link_status=unlinked')->assertOk()->assertJsonPath('total', 24)->assertJsonPath('summary.total', 25);
        $this->getJson($this->url.'?business_id='.$this->otherBusiness)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $other);
        $this->getJson($this->url.'/shops?q=Other%20Shop')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $this->otherBusiness);
        $this->getJson('/api/office/marketplace-categories?q='.$child['slug'])->assertOk()->assertJsonPath('items.0.products_count', 1);
    }

    public function test_product_delete_cascades_link_but_retains_audit_without_affecting_other_products(): void
    {
        $this->login(); $id = $this->product(); $other = $this->product($this->otherBusiness); $category = $this->category();
        $this->link([['id' => $id, 'version' => 0], ['id' => $other, 'version' => 0]], $category['id'])->assertOk();
        DB::table('products')->where('id', $id)->delete();
        $this->assertDatabaseMissing('marketplace_product_categories', ['product_id' => $id]);
        $this->assertDatabaseHas('marketplace_product_categories', ['product_id' => $other]);
        $this->assertDatabaseHas('marketplace_product_category_audits', ['product_id' => $id]);
        $this->getJson($this->url.'/'.$id)->assertNotFound();
    }
}
