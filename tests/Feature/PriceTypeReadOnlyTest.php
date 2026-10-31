<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceTypeReadOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(): array
    {
        $this->withHeader('Origin', 'http://localhost');
        $owner = $this->postJson('/api/auth/register', [
            'business_name' => 'Price type regression', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.test', 'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json();
        $this->actingAs(User::findOrFail($owner['user']['id']), 'web');

        return $owner;
    }

    private function configure(int $business, string $types): void
    {
        DB::table('settings')->where('business_id', $business)->where('key', 'price_types')->update(['value' => $types]);
    }

    private function product(int $business, array $prices, bool $active = true): int
    {
        $id = DB::table('products')->insertGetId(['business_id' => $business, 'name' => 'Product '.Str::uuid(),
            'price' => 100, 'cost' => 50, 'stock' => 5, 'is_active' => $active]);
        foreach ($prices as $name => $price) {
            DB::table('product_prices')->insert(['business_id' => $business, 'product_id' => $id,
                'name' => $name, 'price' => $price, 'is_manual' => true]);
        }

        return $id;
    }

    public function test_repeated_reads_keep_timestamps_and_missing_defaults_without_writes(): void
    {
        $owner = $this->owner();
        $business = $owner['business']['id'];
        $this->configure($business, 'Retail,Legacy,Unused');
        DB::table('price_type_rules')->where('business_id', $business)->where('name', 'Retail')
            ->update(['pricing_mode' => 'automatic', 'markup_percent' => 17.5, 'rounding' => 10,
                'minimum_profit' => 25, 'updated_at' => '2026-01-01 12:00:00']);
        $before = DB::table('price_type_rules')->where('business_id', $business)->orderBy('name')->get()->toJson();
        DB::enableQueryLog();
        try {
            for ($read = 0; $read < 2; $read++) {
                DB::flushQueryLog();
                $this->getJson('/api/price-types')->assertOk()
                    ->assertJsonPath('items', ['Retail', 'Legacy', 'Unused'])
                    ->assertJsonPath('usage', ['Retail' => 0, 'Legacy' => 0, 'Unused' => 0])
                    ->assertJsonPath('rules.0.pricing_mode', 'automatic')
                    ->assertJsonPath('rules.0.markup_percent', 17.5)
                    ->assertJsonPath('rules.0.rounding', 10)
                    ->assertJsonPath('rules.0.minimum_profit', 25)
                    ->assertJsonPath('rules.1', ['name' => 'Legacy', 'pricing_mode' => 'manual',
                        'markup_percent' => 0, 'rounding' => 1, 'minimum_profit' => 0]);
                $queries = DB::getQueryLog();
                $this->assertCount(0, array_filter($queries, fn ($query) => preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query['query'])));
                $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'from `product_prices` as `pp`')));
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($before, DB::table('price_type_rules')->where('business_id', $business)->orderBy('name')->get()->toJson());
    }

    public function test_usage_is_tenant_scoped_excludes_inactive_and_zero_prices_and_matches_name_case(): void
    {
        $first = $this->owner();
        $business = $first['business']['id'];
        $this->configure($business, 'Retail,Wholesale,Unused');
        $this->product($business, ['Retail' => 100, 'wholesale' => 80]);
        $this->product($business, ['Retail' => 100, 'Wholesale' => 0]);
        $this->product($business, ['Retail' => 100, 'Wholesale' => 80], false);
        $other = $this->owner();
        $this->product($other['business']['id'], ['Retail' => 999, 'Wholesale' => 999]);
        $this->actingAs(User::findOrFail($first['user']['id']), 'web');
        $this->getJson('/api/price-types')->assertOk()
            ->assertJsonPath('usage', ['Retail' => 2, 'Wholesale' => 1, 'Unused' => 0]);
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => $business, 'name' => 'Wholesale']);
    }

    public function test_explicit_edit_persists_a_legacy_rule_and_real_crud_remains_available(): void
    {
        $owner = $this->owner();
        $business = $owner['business']['id'];
        $this->configure($business, 'Retail,Legacy');
        $this->getJson('/api/price-types')->assertOk()->assertJsonPath('rules.1.pricing_mode', 'manual');
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => $business, 'name' => 'Legacy']);
        $this->putJson('/api/price-types/Legacy', ['name' => 'Legacy', 'pricing_mode' => 'automatic',
            'markup_percent' => 25, 'rounding' => 10, 'minimum_profit' => 5])->assertOk()
            ->assertJsonPath('rules.1.pricing_mode', 'automatic');
        $this->assertDatabaseHas('price_type_rules', ['business_id' => $business, 'name' => 'Legacy', 'markup_percent' => 25]);
        $this->postJson('/api/price-types', ['name' => 'Wholesale'])->assertOk();
        $this->assertDatabaseHas('price_type_rules', ['business_id' => $business, 'name' => 'Wholesale']);
        $this->deleteJson('/api/price-types/Legacy')->assertOk()->assertJsonPath('items', ['Retail', 'Wholesale']);
    }

    public function test_configured_missing_rule_cannot_be_created_twice_or_edit_an_unknown_type(): void
    {
        $owner = $this->owner();
        $business = $owner['business']['id'];
        $this->configure($business, 'Retail,Legacy');
        $this->postJson('/api/price-types', ['name' => 'legacy'])->assertConflict();
        $this->putJson('/api/price-types/Unknown', ['name' => 'New name'])->assertNotFound();
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => $business, 'name' => 'Legacy']);
    }

    public function test_blank_legacy_configuration_has_a_read_only_retail_default(): void
    {
        $owner = $this->owner();
        $business = $owner['business']['id'];
        $this->configure($business, ' , ');
        DB::table('price_type_rules')->where('business_id', $business)->delete();
        $this->getJson('/api/price-types')->assertOk()->assertJsonPath('items', ['Retail'])
            ->assertJsonPath('rules.0', ['name' => 'Retail', 'pricing_mode' => 'manual',
                'markup_percent' => 0, 'rounding' => 1, 'minimum_profit' => 0]);
        $this->assertDatabaseMissing('price_type_rules', ['business_id' => $business]);
    }
}
