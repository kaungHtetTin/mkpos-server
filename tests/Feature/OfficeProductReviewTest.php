<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\ShopReviews;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeProductReviewTest extends TestCase
{
    use DatabaseTransactions;
    private int $business;
    private int $product;
    private int $customer;
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PlatformAdmin::create(['name' => 'Review Admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->business = DB::table('businesses')->insertGetId(['name' => 'Review Shop '.Str::uuid(), 'slug' => Str::uuid()]);
        $this->product = $this->product($this->business);
        $this->customer = $this->customer();
        $this->purchase($this->business, $this->product, $this->customer);
    }
    private function login(): void { $this->actingAs($this->admin, 'office'); }
    private function customer(): int { return DB::table('platform_customers')->insertGetId(['name' => 'Review Buyer', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]); }
    private function product(int $business): int { return DB::table('products')->insertGetId(['business_id' => $business, 'name' => 'Review Rice', 'sku' => '', 'barcode' => '', 'category' => 'Food', 'price' => 1000, 'cost' => 600, 'base_cost' => 600, 'stock' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]); }
    private function purchase(int $business, int $product, int $customer): int
    {
        $id = DB::table('ecommerce_orders')->insertGetId(['business_id' => $business, 'platform_customer_id' => $customer, 'reference' => Str::uuid(), 'origin' => 'store', 'status' => 'completed', 'payment_status' => 'paid', 'customer_name' => 'Buyer', 'phone' => '09', 'fulfillment' => 'pickup', 'payment_method' => '{}', 'currency' => 'Ks', 'subtotal' => 1000, 'total' => 1000, 'tracking_hash' => Str::random(64), 'idempotency_key' => Str::uuid(), 'request_hash' => Str::random(64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ecommerce_order_items')->insert(['business_id' => $business, 'order_id' => $id, 'product_id' => $product, 'name' => 'Review Rice', 'unit_name' => 'Bag', 'price_type' => 'Retail', 'conversion_factor' => 1, 'quantity' => 1, 'base_quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 600, 'line_total' => 1000]);
        return $id;
    }
    private function create(array $extra = []) { return $this->postJson('/api/office/product-reviews', $extra + ['business_id' => $this->business, 'product_id' => $this->product, 'customer_id' => $this->customer, 'rating' => 4, 'comment' => 'Good rice', 'reply' => null]); }
    private function listing(array $filters = []) { return $this->getJson('/api/office/product-reviews?'.http_build_query($filters + ['business_id' => $this->business])); }

    public function test_review_routes_require_office_authentication(): void
    {
        $this->getJson('/api/office/product-reviews')->assertUnauthorized();
        $this->getJson('/api/office/product-reviews/options?kind=shops')->assertUnauthorized();
        $this->getJson('/api/office/product-reviews/1')->assertUnauthorized();
        $this->postJson('/api/office/product-reviews', [])->assertUnauthorized();
        $this->putJson('/api/office/product-reviews/1', [])->assertUnauthorized();
        $this->deleteJson('/api/office/product-reviews/1', [])->assertUnauthorized();
    }

    public function test_crud_uses_existing_reviews_and_updates_public_rating_summaries(): void
    {
        $this->login(); $row = $this->create()->assertOk()->assertJsonPath('rating', 4)->json();
        $this->assertSame(['count' => 1, 'average' => 4.0], app(ShopReviews::class)->summary($this->business, $this->product));
        $this->getJson('/api/office/product-reviews/'.$row['id'])->assertOk()->assertJsonPath('customer_id', $this->customer);
        $updated = $this->putJson('/api/office/product-reviews/'.$row['id'], ['version' => $row['version'], 'rating' => 2, 'comment' => 'Updated feedback', 'reply' => 'We will help.'])->assertOk()->json();
        $this->assertNotNull($updated['replied_at']);
        $this->assertSame(['count' => 1, 'average' => 2.0], app(ShopReviews::class)->summary($this->business, $this->product));
        $this->putJson('/api/office/product-reviews/'.$row['id'], ['version' => $row['version'], 'rating' => 5])->assertStatus(409);
        $this->deleteJson('/api/office/product-reviews/'.$row['id'], ['version' => $row['version']])->assertStatus(409);
        $this->deleteJson('/api/office/product-reviews/'.$row['id'], ['version' => $updated['version']])->assertOk();
        $this->assertSame(['count' => 0, 'average' => null], app(ShopReviews::class)->summary($this->business, $this->product));
        $this->assertDatabaseMissing('shop_reviews', ['id' => $row['id']]);
    }

    public function test_purchase_eligibility_tenant_scope_duplicates_and_validation(): void
    {
        $this->login(); $stranger = $this->customer();
        $this->create(['customer_id' => $stranger])->assertUnprocessable();
        $other = DB::table('businesses')->insertGetId(['name' => 'Other Review Shop', 'slug' => Str::uuid()]);
        $this->create(['product_id' => $this->product($other)])->assertUnprocessable();
        $this->create(['rating' => 0])->assertUnprocessable(); $this->create(['rating' => 6])->assertUnprocessable();
        $this->create(['comment' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->create(['reply' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->create()->assertOk(); $this->create()->assertStatus(409);
        $this->assertSame(1, DB::table('shop_reviews')->where('business_id', $this->business)->count());
    }

    public function test_shop_reviews_are_excluded_and_seller_reply_changes_cause_conflicts(): void
    {
        $this->login();
        $shop = DB::table('shop_reviews')->insertGetId(['business_id' => $this->business, 'target_id' => 0, 'platform_customer_id' => $this->customer, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->listing()->assertJsonPath('total', 0);
        $this->getJson('/api/office/product-reviews/'.$shop)->assertNotFound();
        $this->putJson('/api/office/product-reviews/'.$shop, ['version' => str_repeat('a', 64), 'rating' => 1])->assertNotFound();
        $this->deleteJson('/api/office/product-reviews/'.$shop, ['version' => str_repeat('a', 64)])->assertNotFound();
        $row = $this->create()->assertOk()->json();
        DB::table('shop_reviews')->where('id', $row['id'])->update(['reply' => 'A new seller response', 'replied_at' => now()]);
        $this->putJson('/api/office/product-reviews/'.$row['id'], ['version' => $row['version'], 'rating' => 1, 'reply' => null])->assertStatus(409);
        $this->assertDatabaseHas('shop_reviews', ['id' => $shop, 'rating' => 5]);
    }

    public function test_filters_sorting_and_pagination(): void
    {
        $this->login(); $row = $this->create(['comment' => 'Find this written review', 'rating' => 1, 'reply' => 'Reply text'])->assertOk()->json();
        for ($i = 0; $i < 11; $i++) DB::table('shop_reviews')->insert(['business_id' => $this->business, 'target_id' => $this->product, 'platform_customer_id' => $this->customer(), 'rating' => 5, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $this->listing(['q' => 'Find this'])->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $row['id']);
        $this->listing(['reply_status' => 'replied', 'content' => 'written', 'rating' => 1])->assertJsonPath('total', 1);
        $this->listing(['reply_status' => 'unreplied', 'content' => 'rating_only'])->assertJsonPath('total', 11);
        $this->listing(['from' => now()->toDateString()])->assertJsonPath('total', 1);
        $this->listing(['to' => now()->subDay()->toDateString()])->assertJsonPath('total', 11);
        $this->listing(['sort' => 'rating_low'])->assertJsonPath('items.0.id', $row['id']);
        $this->listing(['page' => 99, 'per_page' => 10])->assertJsonPath('page', 2)->assertJsonPath('last_page', 2)->assertJsonCount(2, 'items');
        $this->listing(['from' => '2026-10-15', 'to' => '2026-10-01'])->assertUnprocessable();
        $this->listing(['per_page' => 11])->assertUnprocessable();
    }

    public function test_lookup_only_returns_customers_with_completed_purchases_of_this_product(): void
    {
        $this->login(); $stranger = $this->customer();
        $path = '/api/office/product-reviews/options?'.http_build_query(['kind' => 'customers', 'business_id' => $this->business, 'product_id' => $this->product]);
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $this->customer);
        $this->getJson($path.'&selected_id='.$stranger)->assertJsonPath('selected', null);
        $this->getJson('/api/office/product-reviews/options?kind=shops&q=NO-MATCH-'.Str::uuid().'&selected_id='.$this->business)->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('selected.id', $this->business);
        DB::table('ecommerce_orders')->where('business_id', $this->business)->update(['status' => 'pending']);
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
        $this->create()->assertUnprocessable();
        $this->getJson('/api/office/product-reviews/options?kind=customers')->assertUnprocessable();
        $this->getJson('/api/office/product-reviews/options?kind=products&business_id='.$this->business)->assertJsonPath('items.0.id', $this->product);
    }

    public function test_clearing_reply_and_comment_and_editing_deleted_product_reviews(): void
    {
        $this->login(); $row = $this->create(['reply' => 'Original reply'])->assertOk()->json();
        DB::table('products')->where('id', $this->product)->delete();
        $cleared = $this->putJson('/api/office/product-reviews/'.$row['id'], ['version' => $row['version'], 'rating' => 3, 'comment' => '   ', 'reply' => '   '])->assertOk()->assertJsonPath('comment', null)->assertJsonPath('reply', null)->assertJsonPath('replied_at', null)->json();
        $this->assertStringContainsString('Deleted product', $cleared['product_name']);
        $this->putJson('/api/office/product-reviews/'.$row['id'], ['version' => $cleared['version'], 'rating' => 3, 'comment' => '0'])->assertOk()->assertJsonPath('comment', '0');
    }
}
