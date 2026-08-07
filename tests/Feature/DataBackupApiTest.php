<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataBackupApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_export_and_restore_only_their_operational_business_data(): void
    {
        Storage::fake('local');
        $session = $this->registerOwner('Backup Shop', 'backup@example.com');
        $businessId = $session['business']['id'];
        DB::table('settings')->updateOrInsert(
            ['business_id' => $businessId, 'key' => 'admin_pin_hash'],
            ['value' => password_hash('2468', PASSWORD_DEFAULT)]
        );

        $product = $this->postJson('/api/products', [
            'name' => 'Original Product', 'sku' => 'BACKUP-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 2500, 'cost' => 1500, 'stock' => 5, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 2500]],
        ])->assertOk()->json();
        $this->postJson('/api/sales', [
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 2500,
            'discount' => 0, 'items' => [[
                'product_id' => $product['id'], 'price_type' => 'Retail',
                'quantity' => 1, 'foc_quantity' => 0, 'unit_price' => 2500,
            ]],
        ])->assertOk();

        $export = $this->get('/api/data/export')->assertOk()
            ->assertHeader('Content-Type', 'application/gzip');
        $archive = $export->getContent();
        $this->assertStringStartsWith("\x1f\x8b", $archive);

        $laterProduct = $this->postJson('/api/products', [
            'name' => 'Created After Backup', 'sku' => 'LATER-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 1000, 'cost' => 500, 'stock' => 1, 'low_stock_threshold' => 0,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->json();

        $otherBusinessId = DB::table('businesses')->insertGetId([
            'name' => 'Other Shop', 'slug' => 'other-backup-shop-'.uniqid(), 'status' => 'active',
            'timezone' => 'Asia/Yangon', 'currency' => 'Ks', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherProductId = DB::table('products')->insertGetId([
            'business_id' => $otherBusinessId, 'name' => 'Other Business Product', 'sku' => 'OTHER-1',
            'barcode' => '', 'category' => '', 'base_unit' => 'Unit', 'purchase_unit' => null,
            'purchase_conversion_factor' => 1, 'price' => 900, 'cost' => 500, 'base_cost' => 500,
            'stock' => 3, 'low_stock_threshold' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Accept', 'application/json')->post('/api/data/restore-file', [
            'backup' => UploadedFile::fake()->createWithContent('backup.mkpos-backup', $archive),
            'confirmation' => 'RESTORE',
            'admin_pin' => '2468',
        ])->assertOk()->assertJsonPath('safety_backup_created', true);

        $this->assertDatabaseHas('products', ['id' => $product['id'], 'business_id' => $businessId, 'name' => 'Original Product']);
        $this->assertDatabaseMissing('products', ['id' => $laterProduct['id'], 'business_id' => $businessId]);
        $this->assertDatabaseHas('products', ['id' => $otherProductId, 'business_id' => $otherBusinessId, 'name' => 'Other Business Product']);
        $this->assertSame(1, DB::table('sales')->where('business_id', $businessId)->count());
        $this->assertTrue(password_verify('2468', DB::table('settings')->where('business_id', $businessId)->where('key', 'admin_pin_hash')->value('value')));
        $this->assertCount(1, Storage::disk('local')->allFiles('business-backups/safety'));
    }

    public function test_restore_rejects_damaged_and_other_business_backups_without_deleting_data(): void
    {
        Storage::fake('local');
        $first = $this->registerOwner('First Backup Shop', 'first-backup@example.com');
        $product = $this->postJson('/api/products', [
            'name' => 'Protected Product', 'sku' => 'SAFE-1', 'barcode' => '', 'category' => '',
            'price' => 1000, 'cost' => 500, 'stock' => 2, 'low_stock_threshold' => 0,
            'prices' => [['name' => 'Retail', 'price' => 1000]],
        ])->assertOk()->json();
        $archive = $this->get('/api/data/export')->assertOk()->getContent();

        $this->withHeader('Accept', 'application/json')->post('/api/data/restore-file', [
            'backup' => UploadedFile::fake()->createWithContent('damaged.mkpos-backup', 'not a backup'),
            'confirmation' => 'RESTORE',
        ])->assertUnprocessable()->assertJsonValidationErrors('backup');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'business_id' => $first['business']['id']]);

        $this->postJson('/api/auth/logout')->assertOk();
        $second = $this->registerOwner('Second Backup Shop', 'second-backup@example.com');
        $this->withHeader('Accept', 'application/json')->post('/api/data/restore-file', [
            'backup' => UploadedFile::fake()->createWithContent('other.mkpos-backup', $archive),
            'confirmation' => 'RESTORE',
        ])->assertUnprocessable()->assertJsonValidationErrors('backup');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'business_id' => $first['business']['id']]);
        $this->assertDatabaseMissing('products', ['business_id' => $second['business']['id'], 'name' => 'Protected Product']);
    }

    private function registerOwner(string $businessName, string $email): array
    {
        $this->withHeader('Origin', 'http://localhost');
        $session = $this->postJson('/api/auth/register', [
            'business_name' => $businessName, 'owner_name' => 'Backup Owner', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->json();
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Backup Test Plan', 'slug' => 'backup-test-plan-'.uniqid(), 'price' => 1000,
            'currency' => 'Ks', 'duration_days' => 30, 'features' => '[]', 'is_active' => true,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_subscriptions')->insert([
            'business_id' => $session['business']['id'], 'subscription_plan_id' => $planId,
            'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30),
            'price_paid' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $session;
    }
}
