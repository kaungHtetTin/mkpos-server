<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Support\ThemePalette;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(string $role = 'super_admin'): PlatformAdmin
    {
        return PlatformAdmin::create(['name' => 'Management tester', 'email' => Str::uuid().'@example.com', 'password' => Hash::make('test-password'), 'role' => $role, 'is_active' => true]);
    }

    public function test_admin_crud_permissions_password_security_and_audit_identity(): void
    {
        $owner = $this->admin();
        $operator = $this->admin('admin');
        $this->getJson('/api/office/admins')->assertUnauthorized();
        $this->actingAs($operator, 'office')->getJson('/api/office/admins')->assertForbidden();
        $this->putJson('/api/office/branding', ['primary_color' => '#123456'])->assertForbidden();
        $this->actingAs($owner, 'office');
        $email = Str::uuid().'@example.com';
        $created = $this->postJson('/api/office/admins', ['name' => 'Second admin', 'email' => $email, 'role' => 'admin', 'is_active' => true, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk()->assertJsonMissingPath('item.password')->json('item.id');
        $admin = PlatformAdmin::findOrFail($created);
        $this->assertTrue(Hash::check('new-password', $admin->password));
        $token = $admin->createToken('test', ['office'])->plainTextToken;
        $this->deleteJson('/api/office/admins/'.$created)->assertOk();
        $this->assertFalse($admin->fresh()->is_active);
        $this->assertSame(0, $admin->tokens()->count());
        $this->deleteJson('/api/office/admins/'.$owner->id)->assertUnprocessable();
        $this->putJson('/api/office/admins/'.$owner->id, ['name' => $owner->name, 'email' => $owner->email, 'role' => 'admin', 'is_active' => true])->assertUnprocessable();
        $log = DB::table('office_activity_logs')->where('path', 'api/office/admins')->where('method', 'POST')->latest('id')->first();
        $this->assertSame($owner->id, $log->admin_id);
        $this->assertSame($owner->email, $log->admin_email);
        $this->assertStringNotContainsString('password', $log->details);
        $this->assertStringNotContainsString($token, $log->details);
        $this->getJson('/api/office/activity-log?admin_id='.$owner->id.'&per_page=10')->assertOk()->assertJsonPath('per_page', 10);
    }

    public function test_subscription_default_and_per_payment_book_keep_historical_balances_and_actor(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'office');
        $bank = $this->postJson('/api/office/bank-books', ['name' => 'Receipt '.Str::random(8), 'is_active' => true])->assertOk()->json('items');
        $bank = end($bank)['id'];
        $business = DB::table('businesses')->insertGetId(['name' => 'Management shop', 'slug' => Str::uuid()]);
        $plan = DB::table('subscription_plans')->insertGetId(['name' => 'Management plan', 'slug' => Str::uuid(), 'price' => 100, 'currency' => 'MGMT', 'duration_days' => 30]);
        $this->putJson('/api/office/subscription-bank-book', ['subscription_account_id' => $bank])->assertOk();
        $this->putJson('/api/office/businesses/'.$business.'/subscription', ['subscription_plan_id' => $plan])->assertOk();
        $payment = DB::table('subscription_payments')->where('business_id', $business)->first();
        $this->assertSame($bank, $payment->account_id);
        $this->assertSame($admin->id, $payment->created_by_admin_id);
        $this->assertSame($admin->id, DB::table('business_subscriptions')->where('business_id', $business)->value('last_changed_by_admin_id'));
        $operator = $this->admin('admin');
        $this->actingAs($operator, 'office')->patchJson('/api/office/businesses/'.$business.'/subscription/status', ['status' => 'suspended'])->assertOk();
        $this->assertSame($operator->id, DB::table('business_subscriptions')->where('business_id', $business)->value('last_changed_by_admin_id'));
        $audit = DB::table('office_activity_logs')->where('path', 'api/office/businesses/'.$business.'/subscription/status')->latest('id')->first();
        $this->assertSame($operator->id, $audit->admin_id);
        $this->actingAs($admin, 'office');
        $this->putJson('/api/office/subscription-bank-book', ['subscription_account_id' => 2])->assertOk();
        $this->postJson('/api/office/businesses/'.$business.'/subscription/renew', ['account_id' => $bank])->assertOk();
        $this->assertSame(2, DB::table('subscription_payments')->where('business_id', $business)->where('account_id', $bank)->count());
        $report = $this->getJson('/api/office/financial-book?currency=MGMT')->assertOk()->json();
        $this->assertSame('200.00', collect($report['bank_books'])->firstWhere('id', $bank)['balance']);
        $this->deleteJson('/api/office/bank-books/'.$bank)->assertUnprocessable();
        $this->putJson('/api/office/bank-books/'.$bank, ['name' => 'Archived '.Str::random(8), 'is_active' => false])->assertOk();
        $this->postJson('/api/office/businesses/'.$business.'/subscription/renew', ['account_id' => $bank])->assertUnprocessable();
        $this->putJson('/api/office/bank-books/2', ['name' => 'Min Htet Kyaw', 'is_active' => false])->assertUnprocessable();
        $unused = DB::table('office_bank_books')->insertGetId(['name' => 'Unused', 'is_active' => true]);
        $this->deleteJson('/api/office/bank-books/'.$unused)->assertOk();
    }

    public function test_three_founder_allocation_rounding_archive_and_version_conflicts(): void
    {
        $this->actingAs($this->admin(), 'office');
        $version = DB::table('office_book_settings')->where('id', 1)->value('version');
        $founders = [['id' => 1, 'name' => 'One', 'share_bps' => 3333], ['id' => 2, 'name' => 'Two', 'share_bps' => 3333], ['id' => null, 'name' => 'Three', 'share_bps' => 3334]];
        $this->putJson('/api/office/financial-book/founders', ['version' => $version, 'founders' => $founders])->assertOk();
        $third = DB::table('office_founders')->where('name', 'Three')->value('id');
        $currency = 'MG'.Str::random(8);
        $base = ['request_key' => (string) Str::uuid(), 'entry_date' => now()->toDateString(), 'currency' => $currency, 'description' => 'Three-way allocation', 'account_id' => 2];
        $this->postJson('/api/office/financial-book/entries', $base + ['type' => 'income', 'amount' => '1.01'])->assertOk();
        $base['request_key'] = (string) Str::uuid();
        $entry = $this->postJson('/api/office/financial-book/entries', $base + ['type' => 'profit_allocation', 'amount' => '1.01', 'settings_version' => $version + 1])->assertOk()->json('id');
        $snapshot = DB::table('office_book_entries')->where('id', $entry)->value('allocation');
        $shares = json_decode($snapshot, true);
        $this->assertSame(['0.33', '0.33', '0.35'], array_column($shares, 'amount'));
        $this->assertSame($third, $shares[2]['id']);
        $this->putJson('/api/office/financial-book/founders', ['version' => $version, 'founders' => $founders])->assertStatus(409);
        $this->putJson('/api/office/financial-book/founders', ['version' => $version + 1, 'founders' => [['id' => 1, 'name' => 'One renamed', 'share_bps' => 5000], ['id' => 2, 'name' => 'Two', 'share_bps' => 5000]]])->assertOk();
        $this->assertSame($snapshot, DB::table('office_book_entries')->where('id', $entry)->value('allocation'));
        $this->assertFalse((bool) DB::table('office_founders')->where('id', $third)->value('is_active'));
        $report = $this->getJson('/api/office/financial-book?currency='.$currency)->assertOk()->json();
        $this->assertSame('0.35', collect($report['founders'])->firstWhere('id', $third)['paid']);
        $this->postJson('/api/office/financial-book/entries/'.$entry.'/void', ['reason' => 'Test reversal'])->assertOk();
        $this->putJson('/api/office/financial-book/founders', ['version' => $version + 2, 'founders' => [['id' => 1, 'name' => 'Bad total', 'share_bps' => 9999]]])->assertUnprocessable();
    }

    public function test_branding_upload_public_read_and_asset_validation(): void
    {
        Storage::fake('local');
        $this->getJson('/api/branding')->assertOk();
        $this->actingAs($this->admin(), 'office');
        $this->putJson('/api/office/branding', ['primary_color' => 'red; color:black'])->assertUnprocessable();
        $preview = (string) Str::uuid();
        DB::table('platform_theme_previews')->insert(['id' => $preview, 'admin_id' => auth('office')->id(), 'primary_color' => '#4f46e5',
            'palettes' => json_encode(ThemePalette::generate('#4f46e5')), 'rationale' => 'Test palette', 'created_at' => now()]);
        $this->putJson('/api/office/branding', ['primary_color' => '#4f46e5', 'preview_id' => $preview])->assertOk()->assertJsonPath('primary_color', '#4f46e5');
        $this->post('/api/office/branding/logo', ['image' => UploadedFile::fake()->image('logo.png', 80, 80)], ['Accept' => 'application/json'])->assertOk();
        $this->get('/api/branding/assets/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/office/branding/favicon', ['image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson('/api/office/branding/logo')->assertOk()->assertJsonPath('logo_url', null);
    }
}
