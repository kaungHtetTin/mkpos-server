<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_without_subscription_is_limited_to_renewal_flow(): void
    {
        $session = $this->registerBusiness('Blocked Shop', 'blocked@example.com');

        $this->assertFalse($session['subscription']['is_valid']);
        $this->getJson('/api/products')->assertStatus(402)->assertJsonPath('subscription.reason', 'no_subscription');
        $this->getJson('/api/subscription/plans')->assertOk()->assertJsonStructure(['items']);
        $this->getJson('/api/subscription')->assertOk()->assertJsonPath('is_valid', false);
    }

    public function test_office_can_create_plan_approve_request_and_restore_business_access(): void
    {
        Storage::fake('local');
        PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'office@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/office/auth/login', ['email' => 'office@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('admin.email', 'office@example.com');

        $plan = $this->postJson('/api/office/plans', [
            'name' => 'Monthly', 'slug' => 'monthly', 'description' => 'Monthly access',
            'price' => 15000, 'currency' => 'Ks', 'duration_days' => 30,
            'features' => ['POS', 'Reports'], 'is_active' => true, 'sort_order' => 1,
        ])->assertCreated()->json();

        $paymentMethod = $this->postJson('/api/office/payment-methods', [
            'bank' => 'KBZ Bank', 'account_name' => 'MKPOS Co., Ltd.',
            'account_no' => '0123456789', 'is_active' => true, 'sort_order' => 1,
        ])->assertCreated()->json();

        $business = $this->registerBusiness('Subscriber Shop', 'subscriber@example.com');
        $requestId = $this->post('/api/subscription/requests', [
            'subscription_plan_id' => $plan['id'],
            'payment_method_id' => $paymentMethod['id'],
            'payment_screenshot' => UploadedFile::fake()->createWithContent(
                'payment.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlO7ZkAAAAASUVORK5CYII=')
            ),
        ], ['Accept' => 'application/json'])->assertCreated()->json('request_id');

        $this->getJson('/api/office/subscription-requests')
            ->assertOk()
            ->assertJsonPath('items.0.payment_bank', 'KBZ Bank')
            ->assertJsonPath('items.0.payment_account_name', 'MKPOS Co., Ltd.')
            ->assertJsonPath('items.0.payment_account_no', '0123456789')
            ->assertJsonPath('items.0.payment_screenshot_available', true);
        $this->get('/api/office/subscription-requests/'.$requestId.'/payment-screenshot')->assertOk();

        $this->postJson('/api/office/subscription-requests/'.$requestId.'/approve')
            ->assertOk()->assertJsonPath('subscription.is_valid', true);
        $this->getJson('/api/products')->assertOk();

        DB::table('business_subscriptions')->where('business_id', $business['business']['id'])
            ->update(['ends_at' => now()->subMinute()]);
        $this->getJson('/api/products')->assertStatus(402)->assertJsonPath('subscription.reason', 'expired');
    }

    public function test_office_can_manage_payment_methods_and_clients_only_see_active_methods(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'payments-office@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $this->actingAs($admin, 'office');

        $active = $this->postJson('/api/office/payment-methods', [
            'bank' => 'AYA Bank', 'account_name' => 'MKPOS', 'account_no' => '111222333',
            'is_active' => true, 'sort_order' => 2,
        ])->assertCreated()->json();
        $hidden = $this->postJson('/api/office/payment-methods', [
            'bank' => 'CB Bank', 'account_name' => 'MKPOS Billing', 'account_no' => '999888777',
            'is_active' => false, 'sort_order' => 1,
        ])->assertCreated()->json();

        $this->putJson('/api/office/payment-methods/'.$active['id'], [
            'bank' => 'AYA Pay', 'account_name' => 'MKPOS', 'account_no' => '111222333',
            'is_active' => true, 'sort_order' => 0,
        ])->assertOk()->assertJsonPath('bank', 'AYA Pay');
        $officeMethods = collect($this->getJson('/api/office/payment-methods')->assertOk()->json('items'));
        $this->assertTrue($officeMethods->contains('id', $active['id']));
        $this->assertTrue($officeMethods->contains('id', $hidden['id']));

        $this->registerBusiness('Payment Method Shop', 'payment-method-owner@example.com');
        $clientMethods = collect($this->getJson('/api/subscription/payment-methods')->assertOk()->json('items'));
        $this->assertTrue($clientMethods->contains(fn ($method) => $method['id'] === $active['id'] && $method['bank'] === 'AYA Pay'));
        $this->assertFalse($clientMethods->contains('id', $hidden['id']));

        $this->actingAs($admin, 'office');
        $this->deleteJson('/api/office/payment-methods/'.$hidden['id'])->assertOk();
        $remainingMethods = collect($this->getJson('/api/office/payment-methods')->assertOk()->json('items'));
        $this->assertTrue($remainingMethods->contains('id', $active['id']));
        $this->assertFalse($remainingMethods->contains('id', $hidden['id']));
    }

    public function test_office_admin_can_change_login_credentials_securely(): void
    {
        PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'office-settings@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/office/auth/login', [
            'email' => 'office-settings@example.com', 'password' => 'password123',
        ])->assertOk();

        $this->putJson('/api/office/auth/profile', [
            'name' => 'New Platform Owner',
            'email' => 'new-office@example.com',
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/office/auth/profile', [
            'name' => 'New Platform Owner',
            'email' => 'new-office@example.com',
            'current_password' => 'password123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('admin.name', 'New Platform Owner')
            ->assertJsonPath('admin.email', 'new-office@example.com');

        $this->postJson('/api/office/auth/logout')->assertOk();
        $this->postJson('/api/office/auth/login', [
            'email' => 'office-settings@example.com', 'password' => 'password123',
        ])->assertUnprocessable();
        $this->postJson('/api/office/auth/login', [
            'email' => 'new-office@example.com', 'password' => 'new-password-123',
        ])->assertOk()->assertJsonPath('admin.name', 'New Platform Owner');
    }

    public function test_office_can_view_business_billing_history_and_reset_owner_password(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'billing-office@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $business = $this->registerBusiness('Billing Shop', 'billing-owner@example.com');
        $this->actingAs($admin, 'office');

        $plan = $this->postJson('/api/office/plans', [
            'name' => 'Billing Monthly', 'slug' => 'billing-monthly', 'description' => 'Monthly access',
            'price' => 60000, 'currency' => 'Ks', 'duration_days' => 30,
            'features' => ['POS'], 'is_active' => true, 'sort_order' => 1,
        ])->assertCreated()->json();
        $businessId = $business['business']['id'];
        $this->putJson('/api/office/businesses/'.$businessId.'/subscription', [
            'subscription_plan_id' => $plan['id'],
        ])->assertOk();

        $this->getJson('/api/office/businesses/'.$businessId)
            ->assertOk()
            ->assertJsonPath('business.name', 'Billing Shop')
            ->assertJsonPath('business.owner_email', 'billing-owner@example.com')
            ->assertJsonPath('billing.is_valid', true)
            ->assertJsonPath('billing_history.0.plan_name', 'Billing Monthly')
            ->assertJsonPath('billing_history.0.price_paid', 60000)
            ->assertJsonPath('billing_history.0.billing_status', 'active');

        $this->putJson('/api/office/businesses/'.$businessId.'/owner-password', [
            'password' => 'new-owner-password',
            'password_confirmation' => 'new-owner-password',
        ])->assertOk()->assertJsonPath('owner.email', 'billing-owner@example.com');

        Auth::guard('web')->logout();
        $this->postJson('/api/auth/login', [
            'email' => 'billing-owner@example.com', 'password' => 'password123',
        ])->assertUnprocessable();
        $this->postJson('/api/auth/login', [
            'email' => 'billing-owner@example.com', 'password' => 'new-owner-password',
        ])->assertOk()->assertJsonPath('business.id', $businessId);
    }

    public function test_assigning_the_same_valid_plan_extends_from_the_current_end_date(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'extension-office@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $business = $this->registerBusiness('Extension Shop', 'extension-owner@example.com');
        $this->actingAs($admin, 'office');

        $plan = $this->postJson('/api/office/plans', [
            'name' => 'Extension Monthly', 'slug' => 'extension-monthly', 'description' => 'Monthly access',
            'price' => 60000, 'currency' => 'Ks', 'duration_days' => 30,
            'features' => ['POS'], 'is_active' => true, 'sort_order' => 1,
        ])->assertCreated()->json();
        $businessId = $business['business']['id'];

        $this->putJson('/api/office/businesses/'.$businessId.'/subscription', [
            'subscription_plan_id' => $plan['id'],
        ])->assertOk()->assertJsonPath('is_valid', true);

        $initial = DB::table('business_subscriptions')->where('business_id', $businessId)->first();
        $initialEnd = Carbon::parse($initial->ends_at);

        $this->putJson('/api/office/businesses/'.$businessId.'/subscription', [
            'subscription_plan_id' => $plan['id'],
        ])->assertOk()->assertJsonPath('is_valid', true);

        $extended = DB::table('business_subscriptions')->where('business_id', $businessId)->first();
        $this->assertSame(1, DB::table('business_subscriptions')->where('business_id', $businessId)->count());
        $this->assertSame(2, DB::table('subscription_payments')->where('business_id', $businessId)->count());
        $this->assertSame(120000, (int) DB::table('subscription_payments')->where('business_id', $businessId)->sum('amount'));
        $this->assertSame(['assignment', 'renewal'], DB::table('subscription_payments')->where('business_id', $businessId)->orderBy('id')->pluck('type')->all());
        $this->assertEquals($initialEnd->copy()->addDays(30), Carbon::parse($extended->ends_at));
        $this->assertSame($initial->starts_at, $extended->starts_at);
    }

    public function test_office_financial_report_aggregates_month_year_and_all_time_sales(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'report-office@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $business = $this->registerBusiness('Report Shop', 'report-owner@example.com');
        $this->actingAs($admin, 'office');

        $plan = $this->postJson('/api/office/plans', [
            'name' => 'Report Monthly', 'slug' => 'report-monthly', 'description' => 'Monthly access',
            'price' => 60000, 'currency' => 'TST', 'duration_days' => 30,
            'features' => ['POS'], 'is_active' => true, 'sort_order' => 1,
        ])->assertCreated()->json();
        $businessId = $business['business']['id'];

        foreach (range(1, 2) as $assignment) {
            $this->putJson('/api/office/businesses/'.$businessId.'/subscription', [
                'subscription_plan_id' => $plan['id'],
            ])->assertOk();
        }

        $previousPayment = (array) DB::table('subscription_payments')->where('business_id', $businessId)->first();
        unset($previousPayment['id']);
        $previousPayment['amount'] = 30000;
        $previousPayment['paid_at'] = now()->subYear();
        $previousPayment['created_at'] = $previousPayment['paid_at'];
        $previousPayment['updated_at'] = $previousPayment['paid_at'];
        DB::table('subscription_payments')->insert($previousPayment);

        $this->getJson('/api/office/financial-report?currency=TST')
            ->assertOk()
            ->assertJsonPath('currency', 'TST')
            ->assertJsonPath('summary.this_month', 120000)
            ->assertJsonPath('summary.this_year', 120000)
            ->assertJsonPath('summary.previous_year', 30000)
            ->assertJsonPath('summary.all_time', 150000)
            ->assertJsonPath('summary.all_time_sales', 3)
            ->assertJsonPath('summary.year_over_year_percent', 300)
            ->assertJsonPath('monthly.'.(now()->month - 1).'.amount', 120000)
            ->assertJsonCount(12, 'monthly')
            ->assertJsonCount(3, 'recent_sales');
    }

    public function test_business_owner_can_view_only_their_subscription_billing_history(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Platform Owner', 'email' => 'client-billing-office@example.com',
            'password' => Hash::make('password123'), 'is_active' => true,
        ]);
        $business = $this->registerBusiness('Client Billing Shop', 'client-billing-owner@example.com');
        $owner = User::where('email', 'client-billing-owner@example.com')->firstOrFail();
        $otherBusiness = $this->registerBusiness('Other Billing Shop', 'other-billing-owner@example.com');
        $this->actingAs($admin, 'office');

        $plan = $this->postJson('/api/office/plans', [
            'name' => 'Client Monthly', 'slug' => 'client-monthly', 'description' => 'Monthly client plan',
            'price' => 45000, 'currency' => 'Ks', 'duration_days' => 30,
            'features' => ['POS', 'Reports'], 'is_active' => true, 'sort_order' => 1,
        ])->assertCreated()->json();

        $this->putJson('/api/office/businesses/'.$business['business']['id'].'/subscription', [
            'subscription_plan_id' => $plan['id'],
        ])->assertOk();
        $this->putJson('/api/office/businesses/'.$business['business']['id'].'/subscription', [
            'subscription_plan_id' => $plan['id'],
        ])->assertOk();
        $this->putJson('/api/office/businesses/'.$otherBusiness['business']['id'].'/subscription', [
            'subscription_plan_id' => $plan['id'],
        ])->assertOk();

        $this->actingAs($owner, 'web');
        $this->getJson('/api/subscription/billing-history')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.plan_name', 'Client Monthly')
            ->assertJsonPath('items.0.amount', 45000)
            ->assertJsonPath('items.0.currency', 'Ks')
            ->assertJsonPath('items.0.type', 'renewal')
            ->assertJsonPath('items.1.type', 'assignment');

        $this->getJson('/api/subscription/billing-history?with_total=true&limit=1&offset=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.type', 'assignment');
    }

    public function test_business_owner_can_update_profile_and_password_with_current_password(): void
    {
        $business = $this->registerBusiness('Profile Shop', 'profile-owner@example.com');

        $this->putJson('/api/auth/profile', [
            'name' => 'Updated Owner',
            'email' => 'updated-owner@example.com',
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/auth/profile', [
            'name' => 'Updated Owner',
            'email' => 'updated-owner@example.com',
            'current_password' => 'password123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Updated Owner')
            ->assertJsonPath('user.email', 'updated-owner@example.com')
            ->assertJsonPath('business.id', $business['business']['id']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'profile-owner@example.com', 'password' => 'password123',
        ])->assertUnprocessable();
        $this->postJson('/api/auth/login', [
            'email' => 'updated-owner@example.com', 'password' => 'new-password-123',
        ])->assertOk()->assertJsonPath('user.name', 'Updated Owner');
    }

    private function registerBusiness(string $name, string $email): array
    {
        $this->withHeader('Origin', 'http://localhost');

        return $this->postJson('/api/auth/register', [
            'business_name' => $name, 'owner_name' => 'Owner', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonStructure(['user', 'business', 'subscription'])->json();
    }
}
