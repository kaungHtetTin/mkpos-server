<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopReportTest extends TestCase
{
    use DatabaseTransactions;
    private array $owner, $customer, $store;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->postJson('/api/auth/register', ['business_name' => 'Report Shop', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->withToken($this->owner['access_token']);
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $this->store = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        $this->putJson('/api/shop-admin/settings', array_replace($this->store, ['storefront_enabled' => true, 'marketplace_enabled' => true]))->assertOk();
        $this->customer = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Reporter', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($this->customer['access_token']);
    }
    private function path(): string { return '/api/ecommerce/customer/store-reports/'.$this->store['slug']; }
    private function payload(): array { return ['reason' => 'misleading_information', 'details' => 'The advertised shop information is misleading.', 'submission_key' => (string) Str::uuid()]; }
    private function office(): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $admin = PlatformAdmin::create(['name' => 'Office reviewer', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->withToken($admin->createToken('report-test', ['office'])->plainTextToken);
    }
    public function test_reports_require_customer_auth_and_do_not_suspend_the_shop(): void
    {
        $this->withToken(''); $this->postJson($this->path(), $this->payload())->assertUnauthorized();
        $this->withToken($this->owner['access_token']); $this->postJson($this->path(), $this->payload())->assertUnauthorized();
        $this->withToken($this->customer['access_token']);
        $id = $this->postJson($this->path(), $this->payload())->assertOk()->assertJsonPath('created', true)->assertJsonPath('report.status', 'open')->assertJsonMissingPath('report.submission_key')->assertJsonMissingPath('report.customer_id')->json('report.id');
        $this->getJson($this->path())->assertOk()->assertJsonPath('items.0.id', $id);
        $this->assertSame(0, (int) DB::table('storefront_settings')->where('business_id', $this->store['business_id'])->value('marketplace_suspended'));
        $this->getJson('/api/office/ecommerce/reports')->assertUnauthorized();
        $this->withToken($this->owner['access_token']); $this->getJson('/api/office/ecommerce/reports/'.$id)->assertUnauthorized();
    }
    public function test_submission_retries_and_active_duplicates_create_one_report(): void
    {
        $payload = $this->payload();
        $id = $this->postJson($this->path(), $payload)->assertOk()->json('report.id');
        $this->postJson($this->path(), $payload)->assertOk()->assertJsonPath('created', false)->assertJsonPath('report.id', $id);
        $this->postJson($this->path(), $this->payload())->assertOk()->assertJsonPath('created', false)->assertJsonPath('report.id', $id);
        $this->assertSame(1, DB::table('shop_reports')->where('business_id', $this->store['business_id'])->count());
        $this->assertSame(1, DB::table('realtime_activity_events')->where('audience', 'office')->where('event_key', 'shop-report:'.$id)->count());
        DB::table('storefront_settings')->where('business_id', $this->store['business_id'])->update(['storefront_enabled' => false]);
        $this->postJson($this->path(), $payload)->assertOk()->assertJsonPath('report.id', $id);
        $this->getJson($this->path())->assertOk()->assertJsonPath('shop.can_report', false);
    }
    public function test_report_validation_and_customer_scope(): void
    {
        $this->postJson($this->path(), array_replace($this->payload(), ['reason' => 'invalid', 'details' => 'short']))->assertUnprocessable();
        $this->postJson($this->path(), array_replace($this->payload(), ['order_reference' => 'SOMEONE-ELSE']))->assertUnprocessable();
        $id = $this->postJson($this->path(), $this->payload())->assertOk()->json('report.id');
        $other = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Other reporter', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($other['access_token']); $this->getJson($this->path())->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/office/ecommerce/reports/'.$id)->assertUnauthorized();
    }
    public function test_office_review_is_audited_and_internal_notes_are_not_public(): void
    {
        $id = $this->postJson($this->path(), $this->payload())->assertOk()->json('report.id');
        $this->office();
        $url = '/api/office/ecommerce/reports/'.$id;
        $this->getJson('/api/office/ecommerce/reports?status=open&reason=misleading_information&q=Report')->assertOk()->assertJsonPath('items.0.id', $id)->assertJsonMissingPath('items.0.submission_key');
        $this->getJson('/api/office/ecommerce/reports?per_page=100')->assertOk()->assertJsonPath('per_page', 100);
        $review = ['version' => 1, 'status' => 'in_review', 'internal_note' => 'Private investigation note', 'office_response' => 'We are checking the shop information.'];
        $this->putJson($url, $review)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('updates.0.internal_note', 'Private investigation note');
        $this->putJson($url, $review)->assertConflict();
        $this->putJson($url, array_replace($review, ['version' => 2, 'status' => 'resolved', 'office_response' => '']))->assertUnprocessable();
        $this->putJson($url, array_replace($review, ['version' => 2, 'status' => 'resolved', 'office_response' => 'The information has been corrected.']))->assertOk()->assertJsonPath('version', 3)->assertJsonCount(2, 'updates');
        $this->withToken($this->customer['access_token']);
        $this->getJson($this->path())->assertOk()->assertJsonPath('items.0.status', 'resolved')->assertJsonPath('items.0.office_response', 'The information has been corrected.')->assertJsonMissingPath('items.0.updates')->assertJsonMissingPath('items.0.internal_note');
        $this->postJson($this->path(), $this->payload())->assertOk()->assertJsonPath('created', true);
    }
    public function test_closed_report_cannot_reopen_over_another_active_report(): void
    {
        $id = $this->postJson($this->path(), $this->payload())->assertOk()->json('report.id');
        $this->office();
        $this->putJson('/api/office/ecommerce/reports/'.$id, ['status' => 'dismissed', 'version' => 1, 'internal_note' => 'Insufficient evidence', 'office_response' => 'Please supply more details in a new report.'])->assertOk();
        $this->withToken($this->customer['access_token']); $this->postJson($this->path(), $this->payload())->assertOk();
        $this->office(); $this->putJson('/api/office/ecommerce/reports/'.$id, ['status' => 'open', 'version' => 2, 'internal_note' => 'Reopening for investigation'])->assertConflict();
        $this->getJson('/api/office/ecommerce/reports?status=invalid')->assertUnprocessable();
    }
    public function test_order_reference_must_belong_to_the_reporting_customer(): void
    {
        $reference = 'REPORT-'.Str::random(12);
        DB::table('ecommerce_orders')->insert(['business_id' => $this->store['business_id'], 'platform_customer_id' => $this->customer['customer']['id'], 'reference' => $reference,
            'origin' => 'storefront', 'customer_name' => 'Reporter', 'phone' => '0912345', 'fulfillment' => 'pickup', 'payment_method' => '{}', 'currency' => 'Ks',
            'subtotal' => 1000, 'total' => 1000, 'tracking_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64), 'idempotency_key' => Str::uuid(), 'expires_at' => now()->addDay()]);
        $other = $this->postJson('/api/ecommerce/customer/register', ['name' => 'Other buyer', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertOk()->json();
        $this->withToken($other['access_token']);
        $this->postJson($this->path(), array_replace($this->payload(), ['order_reference' => $reference]))->assertUnprocessable();
        $this->withToken($this->customer['access_token']);
        $this->postJson($this->path(), array_replace($this->payload(), ['order_reference' => $reference]))->assertOk()->assertJsonPath('report.order_reference', $reference);
    }
}
