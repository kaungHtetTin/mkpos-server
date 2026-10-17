<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceCategoryTest extends TestCase
{
    use DatabaseTransactions;

    private string $url = '/api/office/marketplace-categories';

    private function login(): PlatformAdmin
    {
        $admin = PlatformAdmin::create(['name' => 'Category staff', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password123'), 'is_active' => true]);
        $this->actingAs($admin, 'office');
        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name_en' => 'Food', 'name_my' => 'အစားအစာ', 'slug' => 'food-'.Str::uuid(),
            'parent_id' => null, 'sort_order' => 0, 'is_active' => true], $overrides);
    }

    private function create(array $overrides = []): array
    {
        return $this->postJson($this->url, $this->payload($overrides))->assertCreated()->json();
    }

    public function test_every_endpoint_requires_office_authentication(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
        $this->getJson($this->url.'/options')->assertUnauthorized();
        $this->getJson($this->url.'/1')->assertUnauthorized();
        $this->postJson($this->url, $this->payload())->assertUnauthorized();
        $this->putJson($this->url.'/1', $this->payload())->assertUnauthorized();
        $this->deleteJson($this->url.'/1')->assertUnauthorized();
    }

    public function test_hierarchy_visibility_and_audit_history(): void
    {
        $admin = $this->login();
        $parent = $this->create();
        $child = $this->create(['name_en' => 'Rice', 'name_my' => 'ဆန်', 'parent_id' => $parent['id']]);
        $this->getJson($this->url.'/'.$child['id'])->assertOk()->assertJsonPath('path', 'Food / Rice')
            ->assertJsonPath('depth', 2)->assertJsonPath('effective_active', true);
        $updated = $this->putJson($this->url.'/'.$parent['id'], array_merge($parent, ['is_active' => false]))
            ->assertOk()->assertJsonPath('version', 2)->json();
        $this->getJson($this->url.'/'.$child['id'])->assertOk()->assertJsonPath('is_active', true)->assertJsonPath('effective_active', false);
        $this->deleteJson($this->url.'/'.$parent['id'], ['version' => 2])->assertConflict();
        $this->deleteJson($this->url.'/'.$child['id'], ['version' => 1])->assertOk();
        $this->deleteJson($this->url.'/'.$parent['id'], ['version' => $updated['version']])->assertOk();
        $this->assertDatabaseMissing('marketplace_categories', ['id' => $parent['id']]);
        $this->assertDatabaseHas('marketplace_category_audits', ['category_id' => $parent['id'], 'admin_id' => $admin->id, 'action' => 'deleted']);
        $audit = DB::table('marketplace_category_audits')->where('category_id', $parent['id'])->where('action', 'updated')->first();
        $this->assertEquals(1, json_decode($audit->before)->is_active);
        $this->assertEquals(0, json_decode($audit->after)->is_active);
    }

    public function test_cycles_invalid_parents_and_duplicate_slugs_are_rejected_without_writes(): void
    {
        $this->login();
        $parent = $this->create();
        $child = $this->create(['parent_id' => $parent['id']]);
        foreach ([$parent['id'], $child['id']] as $id) {
            $this->putJson($this->url.'/'.$parent['id'], array_merge($parent, ['parent_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        $this->create(['name_en' => 'Other']);
        $this->postJson($this->url, $this->payload(['slug' => $parent['slug']]))->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->url, $this->payload(['slug' => '../BAD slug']))->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->url, $this->payload(['parent_id' => 999999999]))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseHas('marketplace_categories', ['id' => $parent['id'], 'parent_id' => null, 'version' => 1]);
        $this->assertEquals(1, DB::table('marketplace_category_audits')->where('category_id', $parent['id'])->count());
    }

    public function test_stale_updates_and_deletions_cannot_overwrite_another_staff_member(): void
    {
        $this->login();
        $category = $this->create();
        $this->putJson($this->url.'/'.$category['id'], array_merge($category, ['name_en' => 'Changed']))->assertOk()->assertJsonPath('version', 2);
        $this->putJson($this->url.'/'.$category['id'], $category)->assertConflict();
        $this->deleteJson($this->url.'/'.$category['id'], ['version' => 1])->assertConflict();
        $this->putJson($this->url.'/'.$category['id'], $this->payload())->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseHas('marketplace_categories', ['id' => $category['id'], 'name_en' => 'Changed', 'version' => 2]);
        $this->assertEquals(2, DB::table('marketplace_category_audits')->where('category_id', $category['id'])->count());
    }

    public function test_search_filters_pagination_and_parent_options_cover_the_full_tree(): void
    {
        $admin = $this->login();
        $existingMyCount = DB::table('marketplace_categories')->where('name_my', 'like', '%အစားအစာ%')->count();
        $prefix = 'qa-'.Str::uuid();
        $root = $this->create(['name_en' => $prefix.' root', 'is_active' => false]);
        $child = $this->create(['name_en' => $prefix.' child', 'parent_id' => $root['id']]);
        $grandchild = $this->create(['name_en' => $prefix.' grandchild', 'parent_id' => $child['id']]);
        $rows = [];
        for ($i = 0; $i < 56; $i++) $rows[] = ['name_en' => $prefix.' item '.sprintf('%02d', $i), 'slug' => $prefix.'-'.$i,
            'is_active' => true, 'sort_order' => $i, 'version' => 1, 'created_by_admin_id' => $admin->id, 'updated_by_admin_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('marketplace_categories')->insert($rows);
        $query = '?q='.urlencode($prefix);
        $this->getJson($this->url.$query.'&per_page=10&page=999')->assertOk()->assertJsonPath('total', 59)->assertJsonPath('page', 6)->assertJsonCount(9, 'items');
        $this->getJson($this->url.$query.'&level=child')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($this->url.$query.'&status=inactive')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $root['id']);
        $this->getJson($this->url.'?q='.urlencode('အစားအစာ'))->assertOk()->assertJsonPath('total', $existingMyCount + 3);
        $first = $this->getJson($this->url.'/options'.$query.'&exclude_id='.$child['id'])->assertOk()->assertJsonCount(50, 'items')->assertJsonPath('has_more', true)->json();
        $second = $this->getJson($this->url.'/options'.$query.'&exclude_id='.$child['id'].'&page=2')->assertOk()->assertJsonCount(7, 'items')->assertJsonPath('has_more', false)->json();
        $ids = array_column(array_merge($first['items'], $second['items']), 'id');
        $this->assertNotContains($child['id'], $ids); $this->assertNotContains($grandchild['id'], $ids); $this->assertContains($root['id'], $ids);
        $this->getJson($this->url.'/options?q='.urlencode($prefix.' item 55'))->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($this->url.'?per_page=999')->assertUnprocessable();
        $this->getJson($this->url.'?status=invalid')->assertUnprocessable();
    }
}
