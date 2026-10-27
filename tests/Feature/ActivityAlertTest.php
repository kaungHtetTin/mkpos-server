<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\RealtimeActivity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityAlertTest extends TestCase
{
    use DatabaseTransactions;
    private Business $business;
    private User $owner;
    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();config(['shop_chat.realtime_enabled' => false]);
        $this->business = Business::create(['name' => 'Activity Shop', 'slug' => Str::uuid(), 'status' => 'active']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'owner', 'is_active' => true]);
        $this->admin = PlatformAdmin::create(['name' => 'Office', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
    }

    public function test_activity_feed_separates_office_and_businesses_and_excludes_staff_even_without_active_subscription(): void
    {
        $service = app(RealtimeActivity::class);
        $other = Business::create(['name' => 'Other shop', 'slug' => Str::uuid()]);
        $service->record('owner', $this->business->id, 'proof_submitted', ['title' => 'Payment proof submitted', 'target' => 'order', 'order_id' => 42]);
        $service->record('owner', $other->id, 'proof_submitted', ['title' => 'Other owner']);
        $service->record('office', $this->business->id, 'shop.reported', ['title' => 'New shop report', 'target' => 'shop-report', 'entity_id' => 7]);
        $this->getJson('/api/activity-alerts')->assertUnauthorized();
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/activity-alerts?after_id=0')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.order_id', 42);
        config(['shop_chat.enabled' => false, 'shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40)]);
        $room = 'private-shop.activity.business.'.$this->business->id;
        $this->getJson('/api/activity-alerts/config')->assertOk()->assertJsonPath('channel', $room);
        $this->postJson('/api/activity-alerts/auth', ['channel_name' => $room])->assertOk();
        $this->postJson('/api/activity-alerts/auth', ['channel_name' => 'private-office.activity'])->assertForbidden();
        $this->owner->role = 'staff';$this->owner->save();
        $this->getJson('/api/activity-alerts')->assertForbidden();
        $this->postJson('/api/activity-alerts/auth', ['channel_name' => $room])->assertForbidden();
        $this->actingAs($this->admin, 'office')->getJson('/api/office/activity-alerts?after_id=0')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.entity_id', 7);
        $this->postJson('/api/office/activity-alerts/auth', ['channel_name' => 'private-office.activity'])->assertOk();
        $this->postJson('/api/office/activity-alerts/auth', ['channel_name' => $room])->assertForbidden();
    }

    public function test_committed_events_publish_identifiers_once_and_rollback_discards_work(): void
    {
        config(['shop_chat.realtime_enabled' => true]);Http::fake();
        DB::beginTransaction();
        $service = app(RealtimeActivity::class);
        $service->record('office', $this->business->id, 'shop.reported', ['title' => 'Private content'], 'same-report');
        $service->record('office', $this->business->id, 'shop.reported', ['title' => 'Private content'], 'same-report');
        Http::assertNothingSent();
        DB::commit();Http::assertSentCount(1);
        Http::assertSent(fn ($request) => json_decode($request->body(), true) === ['channels' => ['private-office.activity'], 'event' => 'activity.changed', 'data' => ['event_id' => (int) DB::table('realtime_activity_events')->where('event_key', 'same-report')->value('id')]]);
        DB::beginTransaction();$service->record('owner', $this->business->id, 'proof_submitted', ['title' => 'Rolled back'], 'rollback');DB::rollBack();
        $this->assertDatabaseMissing('realtime_activity_events', ['event_key' => 'rollback']);Http::assertSentCount(1);
    }

    public function test_feed_drains_bounded_pages_in_order_without_replaying_events(): void
    {
        for ($i = 0; $i < 51; $i++) app(RealtimeActivity::class)->record('office', $this->business->id, 'shop.reported', ['title' => 'Report '.$i]);
        $first = $this->actingAs($this->admin, 'office')->getJson('/api/office/activity-alerts?after_id=0')->assertOk()->assertJsonCount(50, 'items')->assertJsonPath('has_more', true)->json();
        $second = $this->getJson('/api/office/activity-alerts?after_id='.$first['cursor'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', false)->json();
        $this->getJson('/api/office/activity-alerts?after_id='.$second['cursor'])->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_new_subscription_request_alerts_office_and_rejection_alerts_only_its_owner_with_current_access(): void
    {
        Storage::fake('local');
        config(['cache.default' => 'array', 'ai.providers.deepseek.key' => 'test-key']);
        $this->mock(\App\Services\AiAssistantSettings::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['enabled' => true, 'configured' => true]));
        \Illuminate\Support\Facades\Cache::put('ai.deepseek.pricing.v2', ['source_url' => \App\Services\DeepSeekPricing::SOURCE,
            'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => .3, 'output' => 1.2, 'cached_input' => .006]]], 3600);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 1000000000]);
        \App\Ai\Agents\SystemAgent::fake([['readable' => true, 'payment_status' => 'completed', 'amount' => '25000', 'currency' => 'Ks',
            'recipient_account' => '1234', 'recipient_name' => 'MKPOS', 'provider' => 'Test bank', 'transaction_reference' => 'ACTIVITY-'.Str::uuid(),
            'paid_at' => now()->toIso8601String(), 'notes' => '']]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Activity plan', 'slug' => Str::uuid(), 'price' => 25000, 'duration_days' => 30, 'is_system' => false, 'is_public' => true]);
        $payment = DB::table('payment_methods')->insertGetId(['bank' => 'Test bank', 'account_name' => 'MKPOS', 'account_no' => '1234', 'is_active' => true]);
        $requestId = $this->actingAs($this->owner, 'web')->postJson('/api/subscription/requests', [
            'subscription_plan_id' => $plan, 'payment_method_id' => $payment, 'payment_screenshot' => UploadedFile::fake()->image('proof.png'),
        ])->assertCreated()->json('request_id');
        $this->assertDatabaseHas('realtime_activity_events', ['audience' => 'office', 'event_key' => 'subscription-request:'.$requestId]);
        $this->actingAs($this->admin, 'office')->postJson('/api/office/subscription-requests/'.$requestId.'/reject')->assertUnprocessable();
        $this->assertDatabaseHas('subscription_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->actingAs($this->admin, 'office')->postJson('/api/office/subscription-requests/'.$requestId.'/reject', ['admin_note' => 'Please upload a readable transfer proof.'])->assertOk();
        $this->postJson('/api/office/subscription-requests/'.$requestId.'/reject', ['admin_note' => 'Please upload a readable transfer proof.'])->assertNotFound();
        $feed = $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.event', 'subscription.rejected')->assertJsonPath('subscription.is_valid', false)->json();
        $this->assertSame('Please upload a readable transfer proof.', $feed['items'][0]['message']);
    }

    public function test_office_access_changes_send_current_entitlements_and_unchanged_status_does_not_alert_twice(): void
    {
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Access plan', 'slug' => Str::uuid(), 'price' => 25000, 'duration_days' => 30, 'is_system' => false, 'is_public' => true]);
        $url = '/api/office/businesses/'.$this->business->id.'/subscription';
        $this->actingAs($this->admin, 'office')->putJson($url, ['subscription_plan_id' => $plan])->assertOk();
        $first = $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id=0')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('subscription.can_mutate', true)->json('cursor');
        $this->actingAs($this->admin, 'office')->patchJson($url.'/status', ['status' => 'suspended'])->assertOk();
        $this->patchJson($url.'/status', ['status' => 'suspended'])->assertOk();
        $second = $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id='.$first)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.event', 'subscription.suspended')->assertJsonPath('subscription.can_mutate', false)->json('cursor');
        $this->actingAs($this->admin, 'office')->patchJson($url.'/status', ['status' => 'active'])->assertOk();
        $this->actingAs($this->owner, 'web')->getJson('/api/activity-alerts?after_id='.$second)->assertOk()->assertJsonPath('items.0.event', 'subscription.restored')->assertJsonPath('subscription.can_mutate', true);
    }
}
