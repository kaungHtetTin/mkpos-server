<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionAlertTest extends TestCase
{
    use DatabaseTransactions;

    public function test_office_approval_creates_one_private_event_and_publishes_only_after_commit(): void
    {
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('s', 40), 'shop_chat.enabled' => false]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $business = DB::table('businesses')->insertGetId(['name' => 'Approval test', 'slug' => Str::uuid()]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Approved plan', 'slug' => Str::uuid(), 'price' => 25000, 'duration_days' => 30, 'is_system' => false, 'is_public' => true]);
        $requestId = DB::table('subscription_requests')->insertGetId(['business_id' => $business, 'subscription_plan_id' => $plan, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $admin = PlatformAdmin::create(['name' => 'Approval QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        $url = '/api/office/subscription-requests/'.$requestId.'/approve';
        DB::beginTransaction();
        $this->actingAs($admin, 'office')->postJson($url)->assertOk()->assertJsonPath('subscription.is_valid', true);
        $eventId = (int) DB::table('subscription_approval_events')->where('subscription_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $eventId);
        $this->assertSame(1, DB::table('subscription_approval_events')->where('business_id', $business)->count());
        Http::assertNothingSent();
        DB::commit();
        Http::assertSentCount(2);
        $this->postJson($url)->assertNotFound();
        Http::assertSent(fn ($request) => json_decode($request->body(), true) === ['channels' => ['private-shop.subscription.business.'.$business], 'event' => 'subscription.approved', 'data' => ['event_id' => $eventId]]);

        $owner = User::create(['business_id' => $business, 'name' => 'Owner', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner, 'web')->getJson('/api/subscription/alerts')->assertOk()->assertJsonPath('cursor', $eventId)->assertJsonCount(0, 'items');
        $this->getJson('/api/subscription/alerts?after_id=0')->assertOk()->assertJsonPath('items.0.plan_name', 'Approved plan')->assertJsonPath('subscription.is_valid', true);
        $this->getJson('/api/subscription/alerts?after_id='.$eventId)->assertOk()->assertJsonCount(0, 'items');
        $room = 'private-shop.subscription.business.'.$business;
        $this->getJson('/api/subscription/alerts/config')->assertOk()->assertJsonPath('channel', $room);
        $this->postJson('/api/subscription/alerts/auth', ['channel_name' => $room])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/subscription/alerts/auth', ['channel_name' => 'private-shop.subscription.business.2147483647'])->assertForbidden();
        $otherBusiness = DB::table('businesses')->insertGetId(['name' => 'Other business', 'slug' => Str::uuid()]);
        $owner->business_id = $otherBusiness; $owner->unsetRelation('business'); $owner->save();
        $this->getJson('/api/subscription/alerts?after_id=0')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_rolled_back_approval_discards_the_event_and_socket_publication(): void
    {
        config(['shop_chat.realtime_enabled' => true]); Http::fake();
        $business = DB::table('businesses')->insertGetId(['name' => 'Rollback test', 'slug' => Str::uuid()]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Rollback plan', 'slug' => Str::uuid(), 'price' => 25000, 'duration_days' => 30, 'is_system' => false, 'is_public' => true]);
        $requestId = DB::table('subscription_requests')->insertGetId(['business_id' => $business, 'subscription_plan_id' => $plan, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $admin = PlatformAdmin::create(['name' => 'Rollback QA', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'is_active' => true]);
        DB::beginTransaction();
        $this->actingAs($admin, 'office')->postJson('/api/office/subscription-requests/'.$requestId.'/approve')->assertOk();
        DB::rollBack();
        $this->assertFalse(DB::table('subscription_approval_events')->where('subscription_request_id', $requestId)->exists());
        $this->assertSame('pending', DB::table('subscription_requests')->where('id', $requestId)->value('status'));
        Http::assertNothingSent();
    }

    public function test_feed_is_owner_only_and_business_scoped_even_when_subscription_is_expired(): void
    {
        $business = DB::table('businesses')->insertGetId(['name' => 'Empty business', 'slug' => Str::uuid()]);
        $owner = User::create(['business_id' => $business, 'name' => 'Owner', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('password123'), 'role' => 'owner', 'is_active' => true]);
        $this->getJson('/api/subscription/alerts')->assertUnauthorized();
        $this->actingAs($owner, 'web')->getJson('/api/subscription/alerts?after_id=0')->assertOk()->assertJsonCount(0, 'items');
        $owner->role = 'staff'; $owner->save();
        $this->getJson('/api/subscription/alerts')->assertForbidden();
        $this->getJson('/api/subscription/alerts/config')->assertForbidden();
        $this->postJson('/api/subscription/alerts/auth', ['channel_name' => 'private-shop.subscription.business.'.$business])->assertForbidden();
    }
}
