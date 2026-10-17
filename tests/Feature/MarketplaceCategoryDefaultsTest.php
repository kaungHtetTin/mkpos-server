<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceCategoryDefaultsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_deployment_seed_is_repeatable_and_preserves_office_edits(): void
    {
        $migration = require database_path('migrations/2026_10_18_000002_seed_default_marketplace_categories.php');
        $migration->up();
        $count = DB::table('marketplace_categories')->count();
        $root = DB::table('marketplace_categories')->where('slug', 'groceries-food')->first();
        $this->assertNotNull($root);
        DB::table('marketplace_categories')->where('id', $root->id)->update(['name_en' => 'Staff groceries', 'is_active' => false, 'sort_order' => 999, 'version' => 2]);
        $audits = DB::table('marketplace_category_audits')->count();
        $migration->up(); $migration->down();
        $this->assertEquals($count, DB::table('marketplace_categories')->count());
        $this->assertEquals($audits, DB::table('marketplace_category_audits')->count());
        $this->assertDatabaseHas('marketplace_categories', ['id' => $root->id, 'name_en' => 'Staff groceries', 'is_active' => false, 'sort_order' => 999, 'version' => 2]);
        $this->assertDatabaseHas('marketplace_categories', ['slug' => 'rice-grains', 'parent_id' => $root->id]);
    }

    public function test_public_category_list_is_paginated_searchable_and_hides_inactive_ancestors_and_audit_fields(): void
    {
        $root = DB::table('marketplace_categories')->where('slug', 'groceries-food')->first();
        $child = DB::table('marketplace_categories')->where('slug', 'rice-grains')->first();
        $this->getJson('/api/ecommerce/marketplace/categories?level=root&limit=5')->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('has_more', true);
        $first = $this->getJson('/api/ecommerce/marketplace/categories?limit=24')->assertOk()->assertJsonCount(24, 'items')->assertJsonPath('has_more', true)->json();
        $second = $this->getJson('/api/ecommerce/marketplace/categories?offset=24&limit=24')->assertOk()->assertJsonCount(24, 'items')->assertJsonPath('has_more', true)->json();
        $this->assertEmpty(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $response = $this->getJson('/api/ecommerce/marketplace/categories?q=rice-grains')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $child->id)->json('items.0');
        $this->assertArrayHasKey('path_my', $response); $this->assertArrayNotHasKey('created_by_admin_id', $response); $this->assertArrayNotHasKey('version', $response);
        $this->getJson('/api/ecommerce/marketplace/categories?q='.urlencode($child->name_my))->assertOk()->assertJsonPath('items.0.id', $child->id);
        DB::table('marketplace_categories')->where('id', $root->id)->update(['is_active' => false]);
        $this->getJson('/api/ecommerce/marketplace/categories?q=rice-grains')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ecommerce/marketplace/categories?limit=999')->assertUnprocessable();
    }
}
