<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use App\Services\ShopCustomerNotifications;
use Tests\TestCase;

class ShopCustomerFeaturesTest extends TestCase
{
    use DatabaseTransactions;
    private array $owner;
    private array $customer;
    private array $settings;
    private array $product;
    public function test_order_alerts_receive_each_committed_status_once_and_are_customer_scoped(): void
    {
        $baseline = $this->postJson('/api/ecommerce/order-alerts/feed')->assertOk()->json('cursor');
        [$result, $id] = $this->order();
        $this->staff();
        foreach (['accepted', 'accepted', 'packing', 'ready_for_pickup'] as $status) {
            $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => $status])->assertOk();
        }
        $this->buyer();
        $feed = $this->postJson('/api/ecommerce/order-alerts/feed', ['after_id' => $baseline])->assertOk()->assertJsonPath('has_more', false)->json();
        $this->assertSame(['submitted', 'accepted', 'packing', 'ready_for_pickup'], array_column($feed['items'], 'event'));
        $this->assertSame([$result['reference']], array_values(array_unique(array_column($feed['items'], 'reference'))));
        $this->postJson('/api/ecommerce/order-alerts/feed', ['after_id' => $feed['cursor']])->assertOk()->assertJsonCount(0, 'items');
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.enabled' => false, 'shop_chat.secret' => str_repeat('s', 40)]);
        $channel = 'private-shop.orders.customer.'.$this->customer['customer']['id'];
        $this->postJson('/api/ecommerce/order-alerts/config')->assertOk()->assertJsonPath('channel', $channel);
        $this->postJson('/api/ecommerce/order-alerts/auth', ['channel_name' => $channel])->assertOk()->assertJsonStructure(['token', 'expires_at']);
        $this->postJson('/api/ecommerce/order-alerts/auth', ['channel_name' => 'private-shop.orders.customer.2147483647'])->assertForbidden();
        $other = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Other buyer', 'email' => Str::uuid().'@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($other['access_token']);
        $this->postJson('/api/ecommerce/order-alerts/feed', ['after_id' => 0])->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_private_tracking_order_alerts_require_credentials_and_cannot_read_another_order(): void
    {
        [$first] = $this->order();
        [$second] = $this->order();
        $this->withHeader('Authorization', '');
        $credentials = $this->credentials($first);
        $this->postJson('/api/ecommerce/order-alerts/feed')->assertUnauthorized();
        $this->postJson('/api/ecommerce/order-alerts/feed', array_replace($credentials, ['credential' => 'wrong']))->assertNotFound();
        $feed = $this->postJson('/api/ecommerce/order-alerts/feed', $credentials + ['after_id' => 0])->assertOk()->json();
        $this->assertSame([$first['reference']], array_column($feed['items'], 'reference'));
        $this->assertNotContains($second['reference'], array_column($feed['items'], 'reference'));
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40)]);
        $channel = 'private-shop.orders.tracking.'.$first['reference'];
        $this->postJson('/api/ecommerce/order-alerts/config', $credentials)->assertOk()->assertJsonPath('channel', $channel);
        $this->postJson('/api/ecommerce/order-alerts/auth', $credentials + ['channel_name' => $channel])->assertOk();
        $this->postJson('/api/ecommerce/order-alerts/auth', $credentials + ['channel_name' => 'private-shop.orders.tracking.'.$second['reference']])->assertForbidden();
    }

    public function test_customer_order_events_publish_identifiers_after_commit_and_discard_rolled_back_changes(): void
    {
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40)]);
        \Illuminate\Support\Facades\Http::fake();
        DB::beginTransaction();
        [$result, $id] = $this->order();
        $this->staff();
        $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => 'accepted'])->assertOk();
        \Illuminate\Support\Facades\Http::assertNothingSent();
        DB::commit();
        $acceptedId = (int) DB::table('ecommerce_order_events')->where('order_id', $id)->where('event', 'accepted')->value('id');
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => json_decode($request->body(), true) === [
            'channels' => ['private-shop.orders.tracking.'.$result['reference'], 'private-shop.orders.customer.'.$this->customer['customer']['id']],
            'event' => 'order.updated', 'data' => ['event_id' => $acceptedId, 'reference' => $result['reference']]]);
        \Illuminate\Support\Facades\Http::assertSentCount(3); // Submitted customer/owner events, then accepted; rolled-back packing is absent.
        DB::beginTransaction();
        $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => 'packing'])->assertOk();
        DB::rollBack();
        \Illuminate\Support\Facades\Http::assertSentCount(3);
        $this->assertFalse(DB::table('ecommerce_order_events')->where('order_id', $id)->where('event', 'packing')->exists());
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Feature Shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->staff();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $this->settings = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->settings = array_replace($this->settings, ['storefront_enabled' => true, 'accepting_orders' => true, 'marketplace_enabled' => true, 'delivery_zones' => [['id' => 'city', 'name' => 'City', 'fee' => 500]]]);
        $this->putJson('/api/shop-admin/settings', $this->settings)->assertOk();
        $this->product = $this->postJson('/api/products', ['name' => 'Rice', 'category' => 'Food', 'price' => 1000, 'cost' => 600, 'stock' => 10, 'base_unit' => 'Bag', 'purchase_unit' => 'Box', 'purchase_conversion_factor' => 2, 'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => true, 'unit' => 'base', 'description' => 'Rice'])->assertOk();
        $this->customer = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->buyer();
    }
    private function staff(): void { $this->withToken($this->owner['access_token']); }
    public function test_featured_products_rank_sales_favorites_and_reviews_before_pagination(): void
    {
        $tag = 'Featured '.Str::uuid();
        DB::table('products')->where('id', $this->product['id'])->update(['name' => $tag.' Old']);
        $this->staff();
        $new = $this->postJson('/api/products', ['name' => $tag.' New', 'price' => 1000, 'cost' => 600, 'stock' => 10,
            'base_unit' => 'Bag', 'prices' => [['name' => 'Retail', 'price' => 1000]]])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$new['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $path = '/api/ecommerce/marketplace/products?'.http_build_query(['featured' => 1, 'q' => $tag, 'limit' => 1]);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        $business = $this->owner['business']['id'];
        $customer = $this->customer['customer']['id'];
        DB::table('shop_customer_favorites')->insert(['business_id' => $business, 'customer_id' => $customer, 'product_id' => $new['id'], 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $new['id']);
        $this->getJson($path.'&offset=1')->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        $this->getJson($path.'&sort=price_asc')->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        DB::table('shop_customer_favorites')->where('business_id', $business)->delete();
        DB::table('shop_reviews')->insert(['business_id' => $business, 'platform_customer_id' => $customer, 'target_id' => $new['id'], 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $new['id']);
        DB::table('shop_reviews')->where('business_id', $business)->delete();
        [, $orderId] = $this->order(['items' => [['product_id' => $new['id'], 'quantity' => 1]]]);
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $new['id']);
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['sale_voided' => true]);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['sale_voided' => false, 'created_at' => now()->subDays(31)]);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['created_at' => now(), 'payment_status' => 'unpaid']);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.id', $this->product['id']);
    }

    public function test_featured_shop_proximity_is_optional_and_handles_missing_coordinates(): void
    {
        $other = $this->postJson('/api/auth/register', ['business_name' => 'Nearby Shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $newBusiness = $other['business']['id'];
        $oldBusiness = $this->owner['business']['id'];
        DB::table('shop_owner_locations')->insert(['business_id' => $newBusiness,
            'owner_id' => DB::table('users')->where('business_id', $newBusiness)->where('role', 'owner')->value('id'),
            'latitude' => 20, 'longitude' => 96, 'accuracy' => 10, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $settings = (array) DB::table('storefront_settings')->where('business_id', $oldBusiness)->first();
        unset($settings['id']);
        $settings['business_id'] = $newBusiness;
        $settings['slug'] = 'ranking-'.Str::uuid();
        DB::table('storefront_settings')->insert($settings);
        $ids = [$oldBusiness, $newBusiness];
        $ranked = function (array $parameters) use ($ids) {
            $query = DB::table('storefront_settings as sf')->whereIn('sf.business_id', $ids)->select('sf.business_id');
            app(\App\Services\ShopFeaturedRanking::class)->apply($query, new \Illuminate\Http\Request($parameters), false);
            return $query->pluck('sf.business_id')->all();
        };
        $this->assertSame($ids, $ranked(['featured' => 1]));
        $this->assertSame([$newBusiness, $oldBusiness], $ranked(['featured' => 1, 'latitude' => 20, 'longitude' => 96]));
        DB::table('shop_owner_locations')->where('business_id', $newBusiness)->delete();
        $this->assertSame($ids, $ranked(['featured' => 1, 'latitude' => 20, 'longitude' => 96]));
        $customer = $this->customer['customer']['id'];
        DB::table('shop_customer_favorites')->insert(['business_id' => $newBusiness, 'customer_id' => $customer, 'product_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$newBusiness, $oldBusiness], $ranked(['featured' => 1]));
        DB::table('shop_customer_favorites')->where('business_id', $newBusiness)->delete();
        DB::table('shop_reviews')->insert(['business_id' => $newBusiness, 'platform_customer_id' => $customer, 'target_id' => 0, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$newBusiness, $oldBusiness], $ranked(['featured' => 1]));
        [, $orderId] = $this->order();
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $this->assertSame($ids, $ranked(['featured' => 1]));
        DB::table('ecommerce_orders')->where('id', $orderId)->update(['sale_voided' => true]);
        $this->assertSame([$newBusiness, $oldBusiness], $ranked(['featured' => 1]));
    }

    public function test_featured_marketplace_rejects_incomplete_or_invalid_location(): void
    {
        foreach (['shops', 'products'] as $kind) {
            $this->getJson('/api/ecommerce/marketplace/'.$kind.'?featured=1&latitude=20')->assertUnprocessable();
            $this->getJson('/api/ecommerce/marketplace/'.$kind.'?featured=1&latitude=91&longitude=96')->assertUnprocessable();
        }
    }
    private function buyer(): void { $this->withToken($this->customer['access_token']); }
    private function storePath(string $suffix = ''): string { return '/api/ecommerce/stores/'.$this->settings['slug'].$suffix; }
    private function order(array $extra = []): array
    {
        $this->buyer();
        $data = array_replace(['items' => [['product_id' => $this->product['id'], 'quantity' => 2]], 'fulfillment' => 'pickup', 'payment_method_id' => 'cash', 'customer_name' => 'Buyer', 'phone' => '0912345', 'idempotency_key' => (string) Str::uuid()], $extra);
        $data['quote_hash'] = $this->postJson($this->storePath('/quote'), $data)->assertOk()->json('quote_hash');
        $result = $this->postJson($this->storePath('/checkout'), $data)->assertOk()->json();
        return [$result, (int) DB::table('ecommerce_orders')->where('reference', $result['reference'])->value('id')];
    }
    private function credentials(array $result): array { return ['reference' => $result['reference'], 'credential' => $result['tracking_credential']]; }
    private function complete(int $id): void
    {
        $this->staff();
        $this->postJson("/api/shop-admin/orders/$id/payments/confirm", ['amount' => 2000, 'verified' => true, 'reference' => 'Cash'])->assertOk();
        foreach (['accepted', 'packing', 'ready_for_pickup', 'completed'] as $status) $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => $status])->assertOk();
        $this->buyer();
    }

    public function test_product_search_matches_terms_in_any_order_across_public_fields_and_joined_words(): void
    {
        DB::table('products')->where('id', $this->product['id'])->update(['name' => 'Premium Green Tea 100% Natural', 'category' => 'Drinks', 'sku' => 'GT_001', 'barcode' => '1234567890123']);
        DB::table('ecommerce_product_settings')->where('product_id', $this->product['id'])->update(['description' => 'Loose leaves from Shan hills']);
        foreach (['tea GREEN', "  gRee\t te  ", 'greentea', 'drink leav', '001 tea', 'GT001', '7890', '%', '_'] as $search) {
            foreach ([$this->storePath('/products'), '/api/ecommerce/marketplace/products'] as $path) {
                $this->getJson($path.'?'.http_build_query(['q' => $search]))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $this->product['id']);
            }
        }
        foreach (['tea missing', '___', '%%', "' OR 1=1 --"] as $search) {
            $this->getJson($this->storePath('/products?').http_build_query(['q' => $search]))->assertOk()->assertJsonCount(0, 'items');
        }
        $this->getJson($this->storePath('/products?').http_build_query(['q' => ['tea']]))->assertUnprocessable();
        $this->getJson($this->storePath('/products?').http_build_query(['q' => str_repeat('x', 121)]))->assertUnprocessable();
    }

    public function test_product_search_preserves_myanmar_marks_and_public_pagination_boundaries(): void
    {
        DB::table('products')->where('id', $this->product['id'])->update(['name' => 'ရှမ်း လက်ဖက်ခြောက်', 'category' => 'လက်ဖက်']);
        DB::table('ecommerce_product_settings')->where('product_id', $this->product['id'])->update(['description' => 'မွှေးသော အရွက်']);
        foreach (['လက်ဖက် ရှမ်း', 'ရှမ်းလက်ဖက်', 'အရွက် ရှမ်း'] as $search) {
            $this->getJson($this->storePath('/products?').http_build_query(['q' => $search]))->assertOk()->assertJsonCount(1, 'items');
        }
        $this->staff();
        $other = $this->postJson('/api/products', ['name' => 'ရှမ်း လက်ဖက်စိမ်း', 'category' => 'လက်ဖက်', 'price' => 700, 'stock' => 2, 'base_unit' => 'Pack'])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$other['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $query = http_build_query(['q' => 'လက်ဖက် ရှမ်း', 'limit' => 1, 'sort' => 'price_asc']);
        $this->getJson($this->storePath('/products?').$query)->assertOk()->assertJsonPath('items.0.id', $other['id'])->assertJsonPath('has_more', true);
        $this->getJson($this->storePath('/products?').$query.'&offset=1')->assertOk()->assertJsonPath('items.0.id', $this->product['id'])->assertJsonPath('has_more', false);
        $this->putJson('/api/shop-admin/publications/'.$other['id'], ['published' => false, 'unit' => 'base'])->assertOk();
        $this->getJson('/api/ecommerce/marketplace/products?'.$query)->assertOk()->assertJsonPath('items.0.id', $this->product['id'])->assertJsonPath('has_more', false);
        $this->getJson($this->storePath('/products?').http_build_query(['q' => 'ရှမ်း', 'category' => 'Other']))->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_buy_again_uses_current_prices_limits_and_rejects_another_account(): void
    {
        [$result] = $this->order();
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 3]);
        DB::table('product_prices')->where('product_id', $this->product['id'])->where('name', 'Retail')->update(['price' => 1200]);
        $this->postJson('/api/ecommerce/customer/orders/'.$result['reference'].'/buy-again')->assertOk()->assertJsonPath('items.0.price', 1200)->assertJsonPath('items.0.quantity', 1);
        $other = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->json();
        $this->withToken($other['access_token'])->postJson('/api/ecommerce/customer/orders/'.$result['reference'].'/buy-again')->assertNotFound();
    }

    public function test_buy_again_skips_changed_units_and_unpublished_items(): void
    {
        [$result] = $this->order(); $this->staff();
        $this->putJson('/api/shop-admin/publications/'.$this->product['id'], ['published' => true, 'unit' => 'purchase'])->assertOk(); $this->buyer();
        $this->postJson('/api/ecommerce/customer/orders/'.$result['reference'].'/buy-again')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('skipped.0', 'Rice');
    }

    public function test_favorites_are_idempotent_scoped_and_unavailable_rows_are_removable(): void
    {
        $path = '/api/ecommerce/customer/favorites/store/'.$this->settings['slug'];
        $this->putJson($path, ['product_id' => $this->product['id']])->assertOk()->assertJsonPath('saved', true);
        $this->putJson($path, ['product_id' => $this->product['id']])->assertOk();
        $this->putJson($path, ['product_id' => 0])->assertOk();
        $this->getJson('/api/ecommerce/customer/favorites')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/ecommerce/customer/favorites?kind=shops')->assertOk()->assertJsonCount(1, 'items');
        DB::table('ecommerce_product_settings')->where('product_id', $this->product['id'])->update(['published' => false]);
        $this->getJson('/api/ecommerce/customer/favorites')->assertJsonPath('items.0.unavailable', true)->assertJsonMissingPath('items.0.name');
        $this->deleteJson($path, ['product_id' => $this->product['id']])->assertOk()->assertJsonPath('saved', false);
        $this->withHeader('Authorization', '')->getJson('/api/ecommerce/customer/favorites')->assertUnauthorized();
    }

    public function test_address_defaults_and_customer_isolation(): void
    {
        $base = ['label' => 'Home', 'recipient' => 'Buyer', 'phone' => '0912345', 'address' => 'Street A', 'is_default' => false];
        $id = $this->postJson('/api/ecommerce/customer/addresses', $base)->assertOk()->assertJsonPath('items.0.is_default', 1)->json('items.0.id');
        $id2 = $this->postJson('/api/ecommerce/customer/addresses', array_replace($base, ['label' => 'Work', 'address' => 'Street B', 'is_default' => true]))->json('items.0.id');
        $this->assertSame(1, DB::table('shop_customer_addresses')->where('customer_id', $this->customer['customer']['id'])->where('is_default', true)->count());
        $this->getJson('/api/ecommerce/customer/me')->assertJsonPath('customer.address', 'Street B');
        $this->deleteJson('/api/ecommerce/customer/addresses/'.$id2)->assertOk()->assertJsonPath('items.0.id', $id)->assertJsonPath('items.0.is_default', 1);
        $this->putJson('/api/ecommerce/customer/addresses/'.$id, $base)->assertOk()->assertJsonPath('items.0.is_default', 1);
        $this->putJson('/api/ecommerce/customer/profile', ['name' => 'Buyer', 'phone' => '0912345', 'address' => 'Updated Street'])->assertOk();
        $this->getJson('/api/ecommerce/customer/addresses')->assertJsonPath('items.0.address', 'Updated Street');
        $this->putJson('/api/ecommerce/customer/addresses/999999', $base)->assertNotFound();
        $this->postJson('/api/ecommerce/customer/addresses', array_replace($base, ['address' => '']))->assertUnprocessable();
    }

    public function test_order_notifications_are_private_readable_and_do_not_duplicate_transitions(): void
    {
        [$result, $id] = $this->order(); $this->staff();
        $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => 'accepted'])->assertOk();
        $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => 'accepted'])->assertOk(); $this->buyer();
        $rows = $this->getJson('/api/ecommerce/customer/notifications')->assertOk()->assertJsonPath('unread_count', 2)->assertJsonCount(2, 'items')->json('items');
        $this->postJson('/api/ecommerce/customer/notifications/read', ['ids' => [$rows[0]['id']]])->assertOk();
        $this->getJson('/api/ecommerce/customer/notifications?unread=1')->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'items');
        $this->postJson('/api/ecommerce/customer/notifications/read')->assertOk();
        $this->getJson('/api/ecommerce/customer/notifications')->assertJsonPath('unread_count', 0)->assertJsonMissingPath('items.0.payload.credential');
    }

    public function test_pending_unpaid_cancellation_releases_stock_and_retries_are_idempotent(): void
    {
        [$result, $id] = $this->order();
        $data = $this->credentials($result) + ['type' => 'cancellation', 'reason' => 'Ordered by mistake'];
        $this->postJson('/api/ecommerce/order-requests', $data)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('customer_requests.0.status', 'approved');
        $this->postJson('/api/ecommerce/order-requests', $data)->assertOk()->assertJsonCount(1, 'customer_requests');
        $this->assertSame(1, DB::table('realtime_activity_events')->where('event', 'cancellation_requested')->whereJsonContains('payload->order_id', $id)->count());
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'released']);
        $this->postJson('/api/ecommerce/order-requests', array_replace($data, ['credential' => str_repeat('x', 64)]))->assertNotFound();
    }

    public function test_dispatched_cancellation_requires_physical_return_before_release(): void
    {
        [$result, $id] = $this->order(['fulfillment' => 'delivery', 'delivery_zone_id' => 'city', 'address' => 'Street']);
        $this->staff(); foreach (['accepted', 'packing', 'dispatched'] as $status) $this->postJson("/api/shop-admin/orders/$id/transition", ['status' => $status])->assertOk();
        $this->buyer();
        $requestId = $this->postJson('/api/ecommerce/order-requests', $this->credentials($result) + ['type' => 'cancellation', 'reason' => 'No longer needed'])->assertOk()->assertJsonPath('status', 'dispatched')->json('customer_requests.0.id');
        $this->staff();
        $path = "/api/shop-admin/orders/$id/requests/$requestId";
        $this->postJson($path, ['status' => 'approved', 'staff_note' => 'Customer contacted'])->assertUnprocessable();
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active']);
        $this->postJson($path, ['status' => 'approved', 'staff_note' => 'Goods returned', 'return_confirmed' => true])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_catalog_filters_use_sale_prices_reservations_and_stable_pagination(): void
    {
        $this->staff();
        $this->postJson('/api/shop-admin/promotions', ['type' => 'flash_sale', 'name' => 'Rice sale', 'product_id' => $this->product['id'], 'discount_type' => 'percentage', 'discount_value' => 20, 'enabled' => true])->assertOk();
        $this->getJson($this->storePath('/products?min_price=750&max_price=850&on_sale=1&sort=price_asc'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.price', 800);
        $this->getJson('/api/ecommerce/marketplace/products?max_price=850&on_sale=1')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($this->storePath('/products?min_price=900&max_price=800'))->assertUnprocessable();
        $this->getJson($this->storePath('/products?sort=invalid'))->assertUnprocessable();
        $this->order(['items' => [['product_id' => $this->product['id'], 'quantity' => 10]]]);
        $this->getJson($this->storePath('/products?in_stock=1'))->assertJsonCount(0, 'items');
    }

    public function test_related_products_do_not_include_self_unpublished_or_other_shops(): void
    {
        $this->getJson($this->storePath('/products/'.$this->product['id'].'/related'))->assertOk()->assertJsonCount(0, 'items');
        $this->staff();
        $other = $this->postJson('/api/products', ['name' => 'Tea', 'category' => 'Food', 'price' => 700, 'stock' => 2, 'base_unit' => 'Pack'])->assertOk()->json();
        $this->putJson('/api/shop-admin/publications/'.$other['id'], ['published' => true, 'unit' => 'base'])->assertOk();
        $this->getJson($this->storePath('/products/'.$this->product['id'].'/related'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $other['id']);
        DB::table('shop_reviews')->insert(['business_id' => $this->owner['business']['id'], 'platform_customer_id' => $this->customer['customer']['id'], 'target_id' => $other['id'], 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($this->storePath('/products?sort=price_asc&limit=1'))->assertJsonPath('items.0.id', $other['id'])->assertJsonPath('has_more', true);
        $this->getJson($this->storePath('/products?sort=price_asc&limit=1&offset=1'))->assertJsonPath('items.0.id', $this->product['id']);
        $this->getJson($this->storePath('/products?min_rating=4&sort=rating'))->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $other['id']);
        DB::table('ecommerce_product_settings')->where('product_id', $other['id'])->update(['published' => false]);
        $this->getJson($this->storePath('/products/'.$this->product['id'].'/related'))->assertJsonCount(0, 'items');
        $this->getJson($this->storePath('/products/999999/related'))->assertNotFound();
    }

    public function test_paid_cancellation_decline_does_not_release_stock_and_staff_is_tenant_scoped(): void
    {
        [$result, $id] = $this->order(); $this->staff();
        $this->postJson("/api/shop-admin/orders/$id/payments/confirm", ['amount' => 2000, 'verified' => true])->assertOk(); $this->buyer();
        $requestId = $this->postJson('/api/ecommerce/order-requests', $this->credentials($result) + ['type' => 'cancellation', 'reason' => 'Changed delivery plan'])->assertOk()->assertJsonPath('customer_requests.0.status', 'pending')->json('customer_requests.0.id');
        $this->postJson("/api/shop-admin/orders/$id/requests/$requestId", ['status' => 'rejected', 'staff_note' => 'Please contact us'])->assertUnauthorized();
        $other = $this->postJson('/api/auth/register', ['business_name' => 'Other shop', 'owner_name' => 'Other', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($other['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $this->postJson("/api/shop-admin/orders/$id/requests/$requestId", ['status' => 'rejected', 'staff_note' => 'Please contact us'])->assertNotFound();
        $this->staff();
        $this->postJson("/api/shop-admin/orders/$id/requests/$requestId", ['status' => 'rejected', 'staff_note' => 'Please contact us'])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('payment_status', 'paid');
        $this->assertDatabaseHas('ecommerce_stock_reservations', ['order_id' => $id, 'status' => 'active']);
    }

    public function test_receipt_is_credential_protected_and_contains_snapshot_totals(): void
    {
        [$result] = $this->order();
        $this->postJson('/api/ecommerce/tracking', $this->credentials($result))->assertOk()->assertJsonPath('receipt.customer_name', 'Buyer')->assertJsonPath('items.0.unit_price', 1000)->assertJsonPath('total', 2000)->assertJsonMissingPath('tracking_hash');
        $this->postJson('/api/ecommerce/tracking', ['reference' => $result['reference'], 'credential' => str_repeat('x', 64)])->assertNotFound();
    }

    public function test_hours_announcements_closure_and_checkout_retry(): void
    {
        [$result, $id] = $this->order(); $this->staff();
        $hours = array_fill(0, 7, ['closed' => false, 'opens' => '09:00', 'closes' => '18:00']);
        $this->putJson('/api/shop-admin/settings', array_replace($this->settings, ['opening_hours' => $hours, 'announcement' => 'Holiday', 'closed_until' => now()->addDay()->toIso8601String()]))->assertOk();
        $this->getJson($this->storePath())->assertOk()->assertJsonPath('announcement', 'Holiday')->assertJsonPath('accepting_orders', false)->assertJsonCount(7, 'opening_hours');
        $this->postJson($this->storePath('/quote'), ['items' => [['product_id' => $this->product['id'], 'quantity' => 1]], 'fulfillment' => 'pickup', 'payment_method_id' => 'cash'])->assertConflict();
        $hours[0]['closes'] = '08:00';
        $this->putJson('/api/shop-admin/settings', array_replace($this->settings, ['opening_hours' => $hours]))->assertUnprocessable();
    }

    public function test_restock_alerts_send_once_for_available_unreserved_stock(): void
    {
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 0]);
        $path = '/api/ecommerce/customer/stock-alerts/'.$this->settings['slug'].'/'.$this->product['id'];
        $this->putJson($path)->assertOk()->assertJsonPath('active', true);
        $this->assertSame(0, app(ShopCustomerNotifications::class)->restocks());
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 1]);
        $this->assertSame(1, app(ShopCustomerNotifications::class)->restocks());
        $this->assertSame(0, app(ShopCustomerNotifications::class)->restocks());
        $this->getJson('/api/ecommerce/customer/notifications')->assertJsonPath('items.0.event', 'back_in_stock');
        $this->putJson($path)->assertConflict();
        // A second subscription can be fulfilled within the same clock second.
        $this->freezeTime();
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 0]);
        $this->putJson($path)->assertOk()->assertJsonPath('active', true);
        DB::table('products')->where('id', $this->product['id'])->update(['stock' => 1]);
        $this->assertSame(1, app(ShopCustomerNotifications::class)->restocks());
        $this->getJson('/api/ecommerce/customer/notifications')->assertJsonCount(2, 'items');
        $this->travelBack();
    }

    public function test_return_request_approval_does_not_void_sale_or_send_money(): void
    {
        Storage::fake('local');
        [$result, $id] = $this->order(); $this->complete($id);
        $data = $this->credentials($result) + ['type' => 'return', 'reason' => 'Goods are damaged', 'photo' => UploadedFile::fake()->image('damage.jpg')];
        $requestId = $this->post('/api/ecommerce/order-requests', $data, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('customer_requests.0.has_photo', true)->assertJsonMissingPath('customer_requests.0.photo_path')->json('customer_requests.0.id');
        $this->staff();
        $this->getJson('/api/shop-admin/orders?requests=1')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.request_count', 1);
        $this->postJson("/api/shop-admin/orders/$id/requests/$requestId", ['status' => 'approved', 'staff_note' => 'Please return goods'])->assertOk()->assertJsonPath('sale_voided', 0)->assertJsonPath('payment_status', 'paid');
        $this->postJson("/api/shop-admin/orders/$id/requests/$requestId", ['status' => 'resolved', 'staff_note' => 'Refund completed'])->assertConflict();
        $this->getJson("/api/shop-admin/orders/$id/requests/$requestId/photo")->assertOk();
        $this->getJson("/api/shop-admin/orders/999999/requests/$requestId/photo")->assertNotFound();
        DB::table('ecommerce_orders')->where('id', $id)->update(['sale_voided' => true, 'payment_status' => 'refunded']);
        $this->postJson("/api/shop-admin/orders/$id/requests/$requestId", ['status' => 'resolved', 'staff_note' => 'Refund completed'])->assertOk()->assertJsonPath('customer_requests.0.status', 'resolved');
    }
}
