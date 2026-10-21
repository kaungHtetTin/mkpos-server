<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessTimezoneSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'Accept' => 'application/json']);
        $this->owner = $this->register();
        $this->withToken($this->owner['access_token']);
    }

    private function register(): array
    {
        return $this->postJson('/api/auth/register', ['business_name' => 'Time zone shop', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
    }

    public function test_defaults_to_yangon_and_exposes_named_timezones_only_on_the_new_endpoint(): void
    {
        $this->assertSame('Asia/Yangon', $this->owner['business']['timezone']);
        $meta = $this->getJson('/api/settings/timezone')->assertOk()->assertJsonPath('timezone', 'Asia/Yangon')
            ->assertJsonPath('default_timezone', 'Asia/Yangon')->assertJsonPath('storage_timezone', config('app.timezone'))->json();
        $this->assertContains('Asia/Yangon', $meta['options']);
        $this->assertContains('Asia/Bangkok', $meta['options']);
        $this->assertContains('America/New_York', $meta['options']);
        $this->assertArrayNotHasKey('timezone', $this->getJson('/api/settings')->assertOk()->json());
    }

    public function test_owner_changes_business_timezone_without_changing_storage_or_transaction_timestamps(): void
    {
        $businessId = $this->owner['business']['id'];
        $sale = DB::table('sales')->insertGetId(['business_id' => $businessId, 'receipt_no' => 'TZ-'.Str::uuid(),
            'payment_type' => 'cash', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 100, 'created_at' => '2026-10-20 23:45:00']);
        $before = $this->getJson('/api/sales/'.$sale)->assertOk()->json();
        $storageTimezone = config('app.timezone');
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok', 'expected_timezone' => 'Asia/Yangon'])->assertOk()
            ->assertJsonPath('timezone', 'Asia/Bangkok')->assertJsonPath('session.business.timezone', 'Asia/Bangkok')
            ->assertJsonPath('session.storage_timezone', $storageTimezone);
        $this->assertSame($storageTimezone, config('app.timezone'));
        $this->assertSame($before, $this->getJson('/api/sales/'.$sale)->assertOk()->json());
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('business.timezone', 'Asia/Bangkok');
        // 23:45 Yangon is 00:15 the next day in Bangkok.
        $this->getJson('/api/sales?with_total=true&start=2026-10-21&end=2026-10-21')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/sales?with_total=true&start=2026-10-20&end=2026-10-20')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_legacy_settings_save_cannot_reset_timezone_even_when_echoing_a_stale_field(): void
    {
        $legacy = $this->getJson('/api/settings')->assertOk()->json();
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok'])->assertOk();
        $this->putJson('/api/settings', $legacy)->assertOk();
        $this->putJson('/api/settings', $legacy + ['timezone' => 'Asia/Yangon'])->assertOk();
        $this->assertDatabaseHas('businesses', ['id' => $this->owner['business']['id'], 'timezone' => 'Asia/Bangkok']);
        $this->assertSame($legacy, $this->getJson('/api/settings')->assertOk()->json());
    }

    public function test_invalid_values_and_stale_edits_do_not_change_the_saved_timezone(): void
    {
        foreach (['UTC+7', 'Not/AZone', '', null] as $value) $this->putJson('/api/settings/timezone', ['timezone' => $value])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok'])->assertOk();
        $this->putJson('/api/settings/timezone', ['timezone' => 'Europe/London', 'expected_timezone' => 'Asia/Yangon'])->assertConflict();
        $this->getJson('/api/settings/timezone')->assertOk()->assertJsonPath('timezone', 'Asia/Bangkok');
        // A retried successful submission is harmless.
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok', 'expected_timezone' => 'Asia/Yangon'])->assertOk();
    }

    public function test_existing_legacy_alias_is_preserved_until_the_owner_changes_it(): void
    {
        DB::table('businesses')->where('id', $this->owner['business']['id'])->update(['timezone' => 'Asia/Rangoon']);
        $meta = $this->getJson('/api/settings/timezone')->assertOk()->assertJsonPath('timezone', 'Asia/Rangoon')->json();
        $this->assertContains('Asia/Rangoon', $meta['options']);
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Yangon', 'expected_timezone' => 'Asia/Rangoon'])->assertOk();
    }

    public function test_an_empty_legacy_value_uses_yangon_and_is_normalized_only_on_explicit_save(): void
    {
        DB::table('businesses')->where('id', $this->owner['business']['id'])->update(['timezone' => '']);
        $this->getJson('/api/settings/timezone')->assertOk()->assertJsonPath('timezone', 'Asia/Yangon');
        $this->assertDatabaseHas('businesses', ['id' => $this->owner['business']['id'], 'timezone' => '']);
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Yangon', 'expected_timezone' => 'Asia/Yangon'])->assertOk()
            ->assertJsonPath('session.business.timezone', 'Asia/Yangon');
    }

    public function test_staff_and_guests_cannot_change_timezone(): void
    {
        $staff = User::create(['business_id' => $this->owner['business']['id'], 'name' => 'Staff', 'email' => Str::uuid().'@example.test',
            'password' => Hash::make('password123'), 'role' => 'staff', 'is_active' => true]);
        $this->withToken($staff->createToken('timezone-test')->plainTextToken);
        $this->getJson('/api/settings/timezone')->assertForbidden();
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok'])->assertForbidden();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/settings/timezone')->assertUnauthorized();
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok'])->assertUnauthorized();
        $this->assertDatabaseHas('businesses', ['id' => $this->owner['business']['id'], 'timezone' => 'Asia/Yangon']);
    }

    public function test_update_is_scoped_to_the_authenticated_business(): void
    {
        $other = $this->register();
        $this->withToken($this->owner['access_token']);
        $this->putJson('/api/settings/timezone', ['timezone' => 'Asia/Bangkok', 'business_id' => $other['business']['id']])->assertOk();
        $this->assertDatabaseHas('businesses', ['id' => $this->owner['business']['id'], 'timezone' => 'Asia/Bangkok']);
        $this->assertDatabaseHas('businesses', ['id' => $other['business']['id'], 'timezone' => 'Asia/Yangon']);
    }
}
