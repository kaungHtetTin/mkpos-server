<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OfficeTrialExtensionTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_office_admin_can_extend_an_active_trial_without_recording_revenue(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 2, 10, 9, 0, 0, config('app.timezone')));
        $businessId = $this->registerBusiness('Extended Trial Shop', 'extended-trial@example.com');
        $trial = DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'trial')->first();
        $previousEnd = Carbon::parse($trial->ends_at);

        $this->actingAs($this->createAdmin('extend-active@example.com'), 'office')
            ->postJson('/api/office/businesses/'.$businessId.'/subscription/trial/extend', [
                'days' => 10,
                'note' => 'Customer requested more evaluation time',
            ])
            ->assertOk()
            ->assertJsonPath('extension.days_added', 10)
            ->assertJsonPath('extension.reactivated', false)
            ->assertJsonPath('subscription.access_type', 'trial')
            ->assertJsonPath('subscription.is_valid', true);

        $updated = DB::table('business_subscriptions')->where('id', $trial->id)->first();
        $this->assertEquals($previousEnd->copy()->addDays(10), Carbon::parse($updated->ends_at));
        $this->assertSame('active', $updated->status);
        $this->assertStringContainsString('Trial extended 10 days by Trial Extension Admin', $updated->note);
        $this->assertStringContainsString('Customer requested more evaluation time', $updated->note);
        $this->assertSame(0, DB::table('subscription_payments')->where('business_id', $businessId)->count());
    }

    public function test_office_admin_can_reactivate_an_expired_trial_from_server_time(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 5, 20, 8, 30, 0, config('app.timezone')));
        $businessId = $this->registerBusiness('Expired Trial Extension', 'expired-trial-extension@example.com');
        DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'trial')->update([
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDays(10),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->createAdmin('extend-expired@example.com'), 'office')
            ->postJson('/api/office/businesses/'.$businessId.'/subscription/trial/extend', ['days' => 14])
            ->assertOk()
            ->assertJsonPath('extension.reactivated', true)
            ->assertJsonPath('subscription.is_valid', true)
            ->assertJsonPath('subscription.access_type', 'trial');

        $endsAt = DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'trial')->value('ends_at');
        $this->assertEquals(now()->addDays(14), Carbon::parse($endsAt));
    }

    public function test_trial_extension_rejects_paid_access_and_invalid_duration(): void
    {
        $businessId = $this->registerBusiness('Paid Trial Refusal', 'paid-trial-refusal@example.com');
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Paid access', 'slug' => 'paid-trial-refusal', 'description' => '',
            'price' => 10000, 'currency' => 'Ks', 'duration_days' => 30, 'features' => '[]',
            'is_active' => true, 'is_system' => false, 'is_public' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_subscriptions')->insert([
            'business_id' => $businessId, 'subscription_plan_id' => $planId, 'status' => 'active',
            'access_type' => 'paid', 'starts_at' => now(), 'ends_at' => now()->addDays(30),
            'price_paid' => 10000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->createAdmin('extend-refusal@example.com');

        $this->actingAs($admin, 'office')
            ->postJson('/api/office/businesses/'.$businessId.'/subscription/trial/extend', ['days' => 7])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only a business currently on trial access can receive a trial extension.');

        DB::table('business_subscriptions')->where('business_id', $businessId)->where('access_type', 'paid')->delete();
        $this->postJson('/api/office/businesses/'.$businessId.'/subscription/trial/extend', ['days' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');
    }

    private function registerBusiness(string $name, string $email): int
    {
        return (int) $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', [
            'business_name' => $name,
            'owner_name' => 'Trial Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json('business.id');
    }

    private function createAdmin(string $email): PlatformAdmin
    {
        return PlatformAdmin::create([
            'name' => 'Trial Extension Admin',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
    }
}
