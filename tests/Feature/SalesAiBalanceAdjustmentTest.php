<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAiBalanceAdjustmentTest extends TestCase
{
    use DatabaseTransactions;

    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->businessId = $this->postJson('/api/auth/register', ['business_name' => 'Balance fixture', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json('business.id');
    }

    private function url(): string { return '/api/office/businesses/'.$this->businessId.'/sales-ai/wallet'; }

    private function admin(): PlatformAdmin
    {
        $admin = PlatformAdmin::create(['name' => 'Balance reviewer', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('password123'), 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');
        return $admin;
    }

    private function payload(string $balance = '1000'): array
    {
        return ['balance' => $balance, 'expected_balance' => '500.000000', 'reason' => 'Correct opening credit', 'request_key' => (string) Str::uuid()];
    }

    public function test_adjustment_requires_office_auth_and_is_logged_exactly_once(): void
    {
        $data = $this->payload();
        $this->patchJson($this->url(), $data)->assertUnauthorized();
        $admin = $this->admin();
        $this->patchJson($this->url(), $data)->assertOk()->assertJsonPath('wallet.balance', '1000.000000');
        $this->patchJson($this->url(), $data)->assertOk()->assertJsonPath('wallet.balance', '1000.000000');
        $entries = DB::table('sales_ai_entries')->where('business_id', $this->businessId)->where('type', 'adjustment')->get();
        $this->assertCount(1, $entries);
        $this->assertSame(500000000, (int) $entries[0]->amount_micros);
        $this->assertSame($admin->id, (int) $entries[0]->admin_id);
        $this->assertSame('Correct opening credit', $entries[0]->description);
        $this->patchJson($this->url(), array_replace($data, ['balance' => '2000']))->assertStatus(409);
        $this->patchJson($this->url(), $this->payload('2000'))->assertStatus(409);
        $this->assertSame(1000000000, (int) DB::table('sales_ai_wallets')->where('business_id', $this->businessId)->value('balance_micros'));
    }

    public function test_cannot_remove_reserved_credit_and_records_a_debit_without_erasing_history(): void
    {
        $this->admin();
        DB::table('sales_ai_wallets')->where('business_id', $this->businessId)->update(['reserved_micros' => 100000000]);
        $this->patchJson($this->url(), $this->payload('50'))->assertStatus(422);
        $this->patchJson($this->url(), $this->payload('100'))->assertOk()->assertJsonPath('wallet.available', '0.000000')->assertJsonPath('wallet.reserved', '100.000000');
        $this->assertSame(1, DB::table('sales_ai_entries')->where('business_id', $this->businessId)->where('type', 'grant')->count());
        $this->assertSame(-400000000, (int) DB::table('sales_ai_entries')->where('business_id', $this->businessId)->where('type', 'adjustment')->value('amount_micros'));
    }

    public function test_validates_amount_reason_and_allows_zero_when_no_credit_is_reserved(): void
    {
        $this->admin();
        $this->patchJson($this->url(), $this->payload('-1'))->assertUnprocessable();
        $this->patchJson($this->url(), $this->payload('1.0000001'))->assertUnprocessable();
        $this->patchJson($this->url(), array_replace($this->payload(), ['reason' => ' ']))->assertUnprocessable();
        $this->patchJson($this->url(), $this->payload('0'))->assertOk()->assertJsonPath('wallet.balance', '0.000000');
    }
}
