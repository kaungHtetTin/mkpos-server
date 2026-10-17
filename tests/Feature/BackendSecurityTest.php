<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackendSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(): User
    {
        $business = Business::create(['name' => 'Security audit fixture', 'slug' => Str::uuid(), 'status' => 'active']);
        $owner = User::create(['business_id' => $business->id, 'name' => 'Audit owner', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'owner', 'is_active' => true]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Audit plan', 'slug' => Str::uuid(), 'price' => 0, 'currency' => 'Ks', 'duration_days' => 30, 'features' => '[]', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_subscriptions')->insert(['business_id' => $business->id, 'subscription_plan_id' => $plan, 'status' => 'active', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMonth(), 'price_paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return $owner;
    }

    public function test_disabling_office_admin_must_revoke_existing_cookie_session(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Audit admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->postJson('/api/office/auth/login', ['email' => $admin->email, 'password' => 'password123'])->assertOk();
        DB::table('platform_admins')->where('id', $admin->id)->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->getJson('/api/office/plans')->assertUnauthorized();
    }

    public function test_owner_password_change_must_revoke_a_preexisting_stolen_token(): void
    {
        $owner = $this->owner();
        $stolen = $owner->createToken('audit-stolen', ['*'])->plainTextToken;
        $current = $owner->createToken('audit-current', ['*'])->plainTextToken;
        $changed = $this->withHeaders(['X-MKPOS-Auth' => 'token'])->withToken($current)->putJson('/api/auth/profile', ['name' => $owner->name, 'email' => $owner->email, 'current_password' => 'password123', 'password' => 'new-password123', 'password_confirmation' => 'new-password123'])->assertOk();
        $rotated = $changed->json('access_token');
        $this->assertNotEmpty($rotated);
        $this->assertNotSame($current, $rotated);
        Auth::forgetGuards();
        $this->withToken($rotated)->getJson('/api/auth/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($stolen)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_owner_resetting_staff_password_must_revoke_staff_token(): void
    {
        $owner = $this->owner();
        $staff = User::create(['business_id' => $owner->business_id, 'name' => 'Audit staff', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'staff', 'is_active' => true]);
        $stolen = $staff->createToken('audit-stolen', ['*'])->plainTextToken;
        $current = $owner->createToken('audit-current', ['*'])->plainTextToken;
        $this->withHeaders(['X-MKPOS-Auth' => 'token'])->withToken($current)->putJson('/api/staff/'.$staff->id.'/password', ['password' => 'new-password123', 'password_confirmation' => 'new-password123'])->assertOk();
        Auth::forgetGuards();
        $this->withToken($stolen)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_password_reset_must_also_invalidate_shop_admin_session_routes(): void
    {
        $owner = $this->owner();
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'password123'])->assertOk();
        // Simulate the committed password/reset marker written by the OTP reset.
        DB::table('users')->where('id', $owner->id)->update(['password' => Hash::make('new-password123'), 'password_reset_at' => now()]);
        Auth::forgetGuards();
        $this->getJson('/api/shop-admin/owner-location')->assertUnauthorized();
    }

    public function test_office_session_password_change_revokes_tokens_and_keeps_current_session(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Audit admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $token = $admin->createToken('old-office-token', ['office'])->plainTextToken;
        $this->postJson('/api/office/auth/login', ['email' => $admin->email, 'password' => 'password123'])->assertOk();
        $this->putJson('/api/office/auth/profile', ['name' => $admin->name, 'email' => $admin->email, 'current_password' => 'password123', 'password' => 'new-password123', 'password_confirmation' => 'new-password123'])->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/office/auth/me')->assertOk();
        $this->assertSame(0, $admin->tokens()->count());
        Auth::forgetGuards();
        $this->withHeaders(['X-MKPOS-Auth' => 'token'])->withToken($token)->getJson('/api/office/auth/me')->assertUnauthorized();
    }

    public function test_expired_owner_token_cannot_access_business_routes(): void
    {
        $owner = $this->owner();
        $token = $owner->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withHeaders(['X-MKPOS-Auth' => 'token'])->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_office_resetting_owner_password_revokes_owner_tokens_and_sessions(): void
    {
        $owner = $this->owner();
        $token = $owner->createToken('old-owner-token', ['*'])->plainTextToken;
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'password123'])->assertOk();
        $admin = PlatformAdmin::create(['name' => 'Audit admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->actingAs($admin, 'office')->putJson('/api/office/businesses/'.$owner->business_id.'/owner-password', ['password' => 'new-password123', 'password_confirmation' => 'new-password123'])->assertOk();
        $this->assertSame(0, $owner->tokens()->count());
        Auth::forgetGuards();
        $this->getJson('/api/shop-admin/owner-location')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withHeaders(['X-MKPOS-Auth' => 'token'])->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_unconfigured_weak_socket_secret_never_issues_a_ticket(): void
    {
        $owner = $this->owner();
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => 'weak']);
        $this->actingAs($owner, 'web')->postJson('/api/activity-alerts/auth', ['channel_name' => 'private-shop.activity.business.'.$owner->business_id])->assertStatus(503);
    }
}
