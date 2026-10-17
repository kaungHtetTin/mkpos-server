<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\ShopReviews;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeShopReviewTest extends TestCase
{
    use DatabaseTransactions;
    private int $business;
    private int $customer;
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PlatformAdmin::create(['name' => 'Review Admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->business = DB::table('businesses')->insertGetId(['name' => 'Shop Review '.Str::uuid(), 'slug' => Str::uuid()]);
        $this->customer = $this->customer();
        $this->purchase($this->business, $this->customer);
    }
    private function login(): void { $this->actingAs($this->admin, 'office'); }
    private function customer(): int { return DB::table('platform_customers')->insertGetId(['name' => 'Shop Buyer', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]); }
    private function purchase(int $business, int $customer): int
    {
        return DB::table('ecommerce_orders')->insertGetId(['business_id' => $business, 'platform_customer_id' => $customer, 'reference' => Str::uuid(), 'origin' => 'store', 'status' => 'completed', 'payment_status' => 'paid', 'customer_name' => 'Buyer', 'phone' => '09', 'fulfillment' => 'pickup', 'payment_method' => '{}', 'currency' => 'Ks', 'subtotal' => 1000, 'total' => 1000, 'tracking_hash' => Str::random(64), 'idempotency_key' => Str::uuid(), 'request_hash' => Str::random(64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }
    private function create(array $extra = []) { return $this->postJson('/api/office/shop-reviews', $extra + ['business_id' => $this->business, 'customer_id' => $this->customer, 'rating' => 4, 'comment' => 'Helpful shop', 'reply' => null]); }
    private function listing(array $filters = []) { return $this->getJson('/api/office/shop-reviews?'.http_build_query($filters + ['business_id' => $this->business])); }

    public function test_all_routes_require_office_authentication(): void
    {
        $this->getJson('/api/office/shop-reviews')->assertUnauthorized();
        $this->getJson('/api/office/shop-reviews/options?kind=shops')->assertUnauthorized();
        $this->getJson('/api/office/shop-reviews/1')->assertUnauthorized();
        $this->postJson('/api/office/shop-reviews', [])->assertUnauthorized();
        $this->putJson('/api/office/shop-reviews/1', [])->assertUnauthorized();
        $this->deleteJson('/api/office/shop-reviews/1', [])->assertUnauthorized();
    }

    public function test_crud_updates_public_shop_summary_and_preserves_identity(): void
    {
        $this->login(); $row = $this->create()->assertOk()->assertJsonPath('product_id', 0)->json();
        $this->assertSame(['count' => 1, 'average' => 4.0], app(ShopReviews::class)->summary($this->business));
        $this->getJson('/api/office/shop-reviews/'.$row['id'])->assertOk()->assertJsonPath('customer_id', $this->customer);
        $updated = $this->putJson('/api/office/shop-reviews/'.$row['id'], ['version' => $row['version'], 'rating' => 2, 'comment' => 'Updated', 'reply' => '0', 'business_id' => 999999, 'customer_id' => 999999, 'product_id' => 999999])->assertOk()->assertJsonPath('business_id', $this->business)->assertJsonPath('customer_id', $this->customer)->assertJsonPath('product_id', 0)->json();
        $this->assertNotNull($updated['replied_at']);
        $this->assertSame(['count' => 1, 'average' => 2.0], app(ShopReviews::class)->summary($this->business));
        $this->deleteJson('/api/office/shop-reviews/'.$row['id'], ['version' => $row['version']])->assertStatus(409);
        $cleared = $this->putJson('/api/office/shop-reviews/'.$row['id'], ['version' => $updated['version'], 'rating' => 3, 'reply' => ' ', 'comment' => '0'])->assertOk()->assertJsonPath('reply', null)->assertJsonPath('replied_at', null)->assertJsonPath('comment', '0')->json();
        $this->deleteJson('/api/office/shop-reviews/'.$row['id'], ['version' => $cleared['version']])->assertOk();
        $this->assertSame(['count' => 0, 'average' => null], app(ShopReviews::class)->summary($this->business));
    }

    public function test_eligibility_duplicates_and_validation(): void
    {
        $this->login(); $stranger = $this->customer();
        $other = DB::table('businesses')->insertGetId(['name' => 'Other Shop', 'slug' => Str::uuid()]);
        $this->purchase($other, $stranger);
        $this->create(['customer_id' => $stranger])->assertUnprocessable();
        $pending = $this->purchase($this->business, $stranger);
        DB::table('ecommerce_orders')->where('id', $pending)->update(['status' => 'pending']);
        $this->create(['customer_id' => $stranger])->assertUnprocessable();
        $this->create(['product_id' => 42])->assertUnprocessable();
        $this->create(['rating' => 6])->assertUnprocessable();
        $this->create(['rating' => 0])->assertUnprocessable();
        $this->create(['comment' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->create(['reply' => str_repeat('x', 2001)])->assertUnprocessable();
        DB::table('platform_customers')->where('id', $this->customer)->update(['is_active' => false]);
        $this->create()->assertUnprocessable();
        DB::table('platform_customers')->where('id', $this->customer)->update(['is_active' => true]);
        $this->create()->assertOk(); $this->create()->assertStatus(409);
    }

    public function test_product_reviews_are_excluded_and_concurrent_seller_reply_is_protected(): void
    {
        $this->login();
        $productReview = DB::table('shop_reviews')->insertGetId(['business_id' => $this->business, 'target_id' => 999999, 'platform_customer_id' => $this->customer, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->listing()->assertJsonPath('total', 0);
        $this->getJson('/api/office/shop-reviews/'.$productReview)->assertNotFound();
        $this->putJson('/api/office/shop-reviews/'.$productReview, ['version' => str_repeat('a', 64), 'rating' => 1])->assertNotFound();
        $this->deleteJson('/api/office/shop-reviews/'.$productReview, ['version' => str_repeat('a', 64)])->assertNotFound();
        $row = $this->create()->assertOk()->json();
        DB::table('shop_reviews')->where('id', $row['id'])->update(['reply' => 'New seller reply', 'replied_at' => now()]);
        $this->putJson('/api/office/shop-reviews/'.$row['id'], ['version' => $row['version'], 'rating' => 1])->assertStatus(409);
        $this->deleteJson('/api/office/shop-reviews/'.$row['id'], ['version' => $row['version']])->assertStatus(409);
        $this->assertDatabaseHas('shop_reviews', ['id' => $productReview, 'rating' => 5]);
    }

    public function test_customer_lookups_require_completed_purchase_from_selected_shop(): void
    {
        $this->login(); $stranger = $this->customer();
        $path = '/api/office/shop-reviews/options?kind=customers&business_id='.$this->business;
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $this->customer);
        $this->getJson($path.'&selected_id='.$stranger)->assertJsonPath('selected', null);
        $this->getJson($path.'&q=NO-MATCH&selected_id='.$this->customer)->assertJsonCount(0, 'items')->assertJsonPath('selected.id', $this->customer);
        $this->getJson('/api/office/shop-reviews/options?kind=customers')->assertUnprocessable();
        $this->getJson('/api/office/shop-reviews/options?kind=products&business_id='.$this->business)->assertUnprocessable();
        $this->getJson($path.'&product_id=1')->assertUnprocessable();
        DB::table('ecommerce_orders')->where('business_id', $this->business)->update(['status' => 'pending']);
        $this->getJson($path)->assertJsonCount(0, 'items');
    }

    public function test_filters_sorting_and_pagination(): void
    {
        $this->login(); $row = $this->create(['comment' => 'Find this review', 'rating' => 1, 'reply' => 'Reply text'])->assertOk()->json();
        for ($i = 0; $i < 11; $i++) DB::table('shop_reviews')->insert(['business_id' => $this->business, 'target_id' => 0, 'platform_customer_id' => $this->customer(), 'rating' => 5, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $this->listing(['q' => 'Find this'])->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $row['id']);
        $this->listing(['reply_status' => 'replied', 'content' => 'written', 'rating' => 1])->assertJsonPath('total', 1);
        $this->listing(['reply_status' => 'unreplied', 'content' => 'rating_only'])->assertJsonPath('total', 11);
        $this->listing(['from' => now()->toDateString()])->assertJsonPath('total', 1);
        $this->listing(['to' => now()->subDay()->toDateString()])->assertJsonPath('total', 11);
        $this->listing(['sort' => 'rating_low'])->assertJsonPath('items.0.id', $row['id']);
        $this->listing(['page' => 99, 'per_page' => 10])->assertJsonPath('page', 2)->assertJsonCount(2, 'items');
        $this->listing(['from' => '2026-10-15', 'to' => '2026-10-01'])->assertUnprocessable();
    }
}
