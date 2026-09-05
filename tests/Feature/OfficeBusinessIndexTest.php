<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeBusinessIndexTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pagination_filters_signals_and_global_totals(): void
    {
        $prefix = 'IndexQA-'.Str::uuid();
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = DB::table('businesses')->insertGetId(['name' => $prefix.'-'.$i, 'slug' => Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        }
        for ($i = 0; $i < 5; $i++) {
            DB::table('products')->insert(['business_id' => $ids[1], 'name' => 'Product '.$i, 'price' => 100, 'cost' => 50, 'stock' => 10, 'is_active' => true]);
            DB::table('sales')->insert(['business_id' => $ids[2], 'receipt_no' => Str::uuid(), 'payment_type' => 'cash', 'subtotal' => 100, 'total' => 100, 'status' => 'completed', 'created_at' => now()->subDays($i % 2 + 1)]);
        }
        DB::table('sales')->insert(['business_id' => $ids[2], 'receipt_no' => Str::uuid(), 'payment_type' => 'cash', 'subtotal' => 100, 'total' => 100, 'status' => 'completed', 'created_at' => now()->subDays(40)]);
        $planId = DB::table('subscription_plans')->insertGetId(['name' => 'QA', 'slug' => Str::uuid(), 'price' => 100, 'duration_days' => 30]);
        DB::table('subscription_requests')->insert(['business_id' => $ids[3], 'subscription_plan_id' => $planId, 'status' => 'pending']);
        $admin = PlatformAdmin::create(['name' => 'QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        $base = '/api/office/businesses?q='.$prefix.'&sort=potential&per_page=2';
        $first = $this->actingAs($admin, 'office')->getJson($base)->assertOk()->assertJsonPath('total', 4)->assertJsonPath('last_page', 2)
            ->assertJsonPath('items.0.id', $ids[3])->assertJsonPath('items.0.prospect', 'requested')
            ->assertJsonPath('items.1.id', $ids[2])->assertJsonPath('items.1.prospect', 'engaged');
        $this->assertSame(5, (int) $first->json('items.1.sales_30d'));
        $this->assertGreaterThanOrEqual(4, $first->json('summary.total'));
        $this->getJson($base.'&page=2')->assertOk()->assertJsonPath('items.0.id', $ids[1])->assertJsonPath('items.0.prospect', 'setup')->assertJsonPath('items.1.id', $ids[0]);
        $this->getJson($base.'&page=999')->assertOk()->assertJsonPath('page', 2);
        $this->getJson($base.'&prospect=engaged')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $ids[2]);
        $this->getJson('/api/office/businesses?per_page=101')->assertUnprocessable();
        $this->getJson('/api/office/businesses?sort=unsafe')->assertUnprocessable();
        DB::table('business_subscriptions')->insert(['business_id' => $ids[2], 'subscription_plan_id' => $planId, 'access_type' => 'paid', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10)]);
        $this->getJson($base.'&access=paid')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.prospect', 'paid')->assertJsonPath('items.0.subscription.is_valid', true);
        $this->getJson($base.'&prospect=engaged')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('items', []);
    }

    public function test_business_index_requires_office_authentication(): void
    {
        $this->getJson('/api/office/businesses')->assertUnauthorized();
    }
}
