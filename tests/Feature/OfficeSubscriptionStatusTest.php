<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeSubscriptionStatusTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_office_admin_can_suspend_and_reactivate_current_subscription_without_changing_its_period(): void
    {
        Carbon::setTestNow('2028-06-10 09:00:00 UTC');
        $businessId = $this->registerBusiness();
        $subscription = DB::table('business_subscriptions')->where('business_id', $businessId)->first();
        $admin = PlatformAdmin::create([
            'name' => 'Subscription Operator',
            'email' => Str::uuid().'@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $url = '/api/office/businesses/'.$businessId.'/subscription/status';

        $this->actingAs($admin, 'office')->patchJson($url, ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('can_mutate', false)
            ->assertJsonPath('reason', 'cancelled')
            ->assertJsonPath('lifecycle_notice.stage', 'suspended');

        $suspended = DB::table('business_subscriptions')->where('id', $subscription->id)->first();
        $this->assertSame('cancelled', $suspended->status);
        $this->assertSame($subscription->starts_at, $suspended->starts_at);
        $this->assertSame($subscription->ends_at, $suspended->ends_at);

        $this->patchJson($url, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('can_mutate', true)
            ->assertJsonPath('reason', null);

        $reactivated = DB::table('business_subscriptions')->where('id', $subscription->id)->first();
        $this->assertSame('active', $reactivated->status);
        $this->assertSame($subscription->starts_at, $reactivated->starts_at);
        $this->assertSame($subscription->ends_at, $reactivated->ends_at);
    }

    public function test_status_change_requires_office_authentication_and_a_supported_state(): void
    {
        $businessId = $this->registerBusiness();
        $url = '/api/office/businesses/'.$businessId.'/subscription/status';

        $this->patchJson($url, ['status' => 'suspended'])->assertUnauthorized();

        $admin = PlatformAdmin::create([
            'name' => 'Subscription Validator',
            'email' => Str::uuid().'@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'office')->patchJson($url, ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function registerBusiness(): int
    {
        return (int) $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', [
            'business_name' => 'Status Toggle '.Str::random(8),
            'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json('business.id');
    }
}
