<?php

namespace Tests\Feature;

use App\Services\ProductWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use DatabaseTransactions;

    private function signIn(): array
    {
        $this->withHeaders(['Origin' => 'http://127.0.0.1:5176', 'Referer' => 'http://127.0.0.1:5176/products']);
        $session = $this->postJson('/api/auth/register', [
            'business_name' => 'Import Test Shop', 'owner_name' => 'Import Owner',
            'email' => 'import-'.uniqid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->json();
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'Import Test', 'slug' => 'import-test-'.uniqid(), 'price' => 1000, 'currency' => 'Ks',
            'duration_days' => 30, 'features' => '[]', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_subscriptions')->insert([
            'business_id' => $session['business']['id'], 'subscription_plan_id' => $planId, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => now()->addDays(30), 'price_paid' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $session;
    }

    private function upload(string $csv)
    {
        return $this->post('/api/product-imports', ['file' => UploadedFile::fake()->createWithContent('products.csv', $csv)], ['Accept' => 'application/json']);
    }

    public function test_237_products_are_reviewed_and_imported_in_five_requests_without_duplicate_prices_or_stock(): void
    {
        $session = $this->signIn();
        $csv = "\xEF\xBB\xBFName,SKU,Barcode,Category,Cost,Stock,Low Stock Alert,Retail,Wholesale\r\n";
        for ($i = 1; $i <= 237; $i++) {
            $csv .= "Product {$i},,,,100,2,3,200,150\r\n";
        }
        $preview = $this->upload($csv)->assertOk()->assertJsonPath('ready', 237)->assertJsonPath('invalid', 0)->json();
        $businessId = $session['business']['id'];
        $this->assertSame(0, DB::table('products')->where('business_id', $businessId)->count());
        $path = '/api/product-imports/'.$preview['id'];
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson($path.'/process')->assertOk()->assertJsonPath('imported', min($i * 50, 237));
        }
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('imported', 237);
        $this->upload($csv)->assertOk()->assertJsonPath('id', $preview['id'])->assertJsonPath('status', 'completed');
        $this->assertSame(237, DB::table('products')->where('business_id', $businessId)->count());
        $this->getJson('/api/products?with_total=true&limit=50')->assertOk()->assertJsonPath('total', 237)
            ->assertJsonCount(50, 'items')->assertJsonPath('items.0.is_active', true);
        $this->getJson('/api/products/summary')->assertOk()->assertJsonPath('total_products', 237);
        $this->assertSame(474, DB::table('product_prices')->where('business_id', $businessId)->count());
        $this->assertSame(237, DB::table('stock_movements')->where('business_id', $businessId)->where('movement_type', 'opening_stock')->count());
        $this->getJson($path.'/rows?with_total=true&limit=20&offset=20')->assertOk()->assertJsonPath('total', 237)
            ->assertJsonCount(20, 'items')->assertJsonPath('items.0.row_number', 22)->assertJsonPath('items.0.status', 'imported');
    }

    public function test_csv_quoting_unicode_and_unit_conversions_preserve_product_data(): void
    {
        $session = $this->signIn();
        $preview = $this->upload("Name,Cost,Stock,Retail,Wholesale,Base Unit,Purchase Unit,Units per Purchase Unit\n\"Filter, \"\"အပို\"\"\nsecond line\",\"1,000\",1.5,2000,0,Piece,Box,12")
            ->assertOk()->assertJsonPath('ready', 1)->json();
        $this->postJson('/api/product-imports/'.$preview['id'].'/process')->assertOk()->assertJsonPath('imported', 1);
        $product = DB::table('products')->where('business_id', $session['business']['id'])->first();
        $this->assertSame("Filter, \"အပို\"\nsecond line", $product->name);
        $this->assertSame(1000, (int) $product->cost);
        $this->assertSame(1.5, (float) $product->stock);
        $this->assertSame(12.0, (float) $product->purchase_conversion_factor);
        $this->assertSame(0, (int) DB::table('product_prices')->where('product_id', $product->id)->where('name', 'Wholesale')->value('price'));
    }

    public function test_invalid_rows_block_confirmation_and_return_row_errors(): void
    {
        $session = $this->signIn();
        $preview = $this->upload("Name,Cost,Retail\n,100,200\nRice,bad,200\nTea,1.5,200\nWater,100,200,extra")
            ->assertOk()->assertJsonPath('invalid', 4)->json();
        $path = '/api/product-imports/'.$preview['id'];
        $this->postJson($path.'/process')->assertUnprocessable();
        $this->getJson($path.'/rows?with_total=true')->assertOk()->assertJsonPath('items.0.status', 'invalid')
            ->assertJsonPath('items.3.errors.0', 'The number of values does not match the column headings.');
        $this->assertSame(0, DB::table('products')->where('business_id', $session['business']['id'])->count());
        $this->upload("Wrong,Cost\nRice,100")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload("Name,Name\nRice,Rice")->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_existing_identifiers_and_repeated_codes_are_skipped_but_names_are_not_merged(): void
    {
        $session = $this->signIn();
        $this->postJson('/api/products', ['name' => 'Existing', 'sku' => 'OLD', 'barcode' => '885001', 'price' => 100])->assertOk();
        $preview = $this->upload("Name,SKU,Barcode,Cost,Retail\nKeep existing,OLD,,50,200\nKeep barcode,,885001,50,200\nNew,NEW,,50,200\nRepeated,NEW,,50,200\nExisting,,,50,200")
            ->assertOk()->assertJsonPath('skipped', 3)->assertJsonPath('ready', 2)->json();
        $this->postJson('/api/product-imports/'.$preview['id'].'/process')->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('skipped', 3);
        $this->assertSame(3, DB::table('products')->where('business_id', $session['business']['id'])->count());
        $this->assertSame(100, (int) DB::table('products')->where('business_id', $session['business']['id'])->where('sku', 'OLD')->value('price'));
    }

    public function test_reupload_reimports_deleted_products_and_keeps_active_products_and_retries_unchanged(): void
    {
        $session = $this->signIn();
        // Names without codes reproduce the user's CSV and must not duplicate
        // the surviving product when only part of a saved import was deleted.
        $csv = "Name,Cost,Stock,Retail\nRice,100,2,200\nTea,100,3,200";
        $preview = $this->upload($csv)->assertOk()->json();
        $path = '/api/product-imports/'.$preview['id'];
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('status', 'completed');
        $riceId = DB::table('products')->where('business_id', $session['business']['id'])->where('name', 'Rice')->value('id');
        $teaId = DB::table('products')->where('business_id', $session['business']['id'])->where('name', 'Tea')->value('id');
        $this->deleteJson('/api/products/'.$riceId)->assertOk();
        // Merely viewing/resuming old results must not undo a deletion.
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('status', 'completed');
        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('total', 1);

        $this->upload($csv)->assertOk()->assertJsonPath('id', $preview['id'])->assertJsonPath('status', 'preview')
            ->assertJsonPath('ready', 1)->assertJsonPath('imported', 1);
        $this->upload($csv)->assertOk()->assertJsonPath('ready', 1);
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('status', 'completed');
        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/products/summary')->assertOk()->assertJsonPath('total_stock_units', 5);
        $this->assertSame(1, DB::table('products')->where('business_id', $session['business']['id'])->where('name', 'Tea')->count());
        $this->assertTrue((bool) DB::table('products')->where('id', $teaId)->value('is_active'));
        $this->assertFalse((bool) DB::table('products')->where('id', $riceId)->value('is_active'));
        $this->assertSame(3, DB::table('products')->where('business_id', $session['business']['id'])->count());
        $this->assertSame(3, DB::table('stock_movements')->where('business_id', $session['business']['id'])->where('movement_type', 'opening_stock')->count());
        $this->upload($csv)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('ready', 0);
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('imported', 2);
        $this->assertSame(3, DB::table('products')->where('business_id', $session['business']['id'])->count());

        $ids = DB::table('products')->where('business_id', $session['business']['id'])->where('is_active', true)->pluck('id')->all();
        $this->postJson('/api/products/bulk-delete', ['ids' => $ids])->assertOk()->assertJsonPath('deleted_count', 2);
        $this->upload($csv)->assertOk()->assertJsonPath('status', 'preview')->assertJsonPath('ready', 2)->assertJsonPath('imported', 0);
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('imported', 2);
        $this->getJson('/api/products?with_total=true')->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(5, DB::table('products')->where('business_id', $session['business']['id'])->count());
    }

    public function test_imports_and_row_results_cannot_be_read_or_processed_from_another_business(): void
    {
        $this->signIn();
        $preview = $this->upload("Name,Cost,Retail\nPrivate product,100,200")->assertOk()->json();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signIn();
        $path = '/api/product-imports/'.$preview['id'];
        $this->getJson($path)->assertNotFound();
        $this->getJson($path.'/rows')->assertNotFound();
        $this->getJson($path.'/rows?issues_only=true&with_total=true')->assertNotFound();
        $this->postJson($path.'/process')->assertNotFound();
        $this->getJson('/api/product-imports?with_total=true')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_issues_only_returns_paginated_invalid_and_failed_rows_without_successful_rows(): void
    {
        $this->signIn();
        $preview = $this->upload("Name,Cost,Retail\nValid,100,200\nInvalid,bad,200\nFailed,100,200\nImported,100,200\nSkipped,100,200")
            ->assertOk()->assertJsonPath('invalid', 1)->json();
        $path = '/api/product-imports/'.$preview['id'];
        foreach (['Failed' => 'failed', 'Imported' => 'imported', 'Skipped' => 'skipped'] as $name => $status) {
            DB::table('product_import_rows')->where('import_id', $preview['id'])->where('name', $name)
                ->update(['status' => $status, 'errors' => json_encode($status === 'failed' ? ['Product could not be created.'] : [])]);
        }
        $this->getJson($path.'/rows?issues_only=true&with_total=true&limit=1&offset=0')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(1, 'items')->assertJsonPath('items.0.row_number', 3)->assertJsonPath('items.0.status', 'invalid');
        $this->getJson($path.'/rows?issues_only=true&with_total=true&limit=1&offset=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('items.0.row_number', 4)->assertJsonPath('items.0.errors.0', 'Product could not be created.');
        $this->getJson($path.'/rows?with_total=true')->assertOk()->assertJsonPath('total', 5)->assertJsonCount(5, 'items');
    }

    public function test_unexpected_failure_rolls_back_products_and_checkpoints_then_resume_is_safe(): void
    {
        $session = $this->signIn();
        $preview = $this->upload("Name,Cost,Stock,Retail\nOne,100,2,200\nTwo,100,2,200")->assertOk()->json();
        $original = app(ProductWriter::class);
        $writer = \Mockery::mock(ProductWriter::class)->makePartial();
        $calls = 0;
        $writer->shouldReceive('create')->andReturnUsing(function ($data, $userId) use (&$calls, $original) {
            if (++$calls === 2) {
                throw new \RuntimeException('Simulated interrupted chunk');
            }

            return $original->create($data, $userId);
        });
        $this->app->instance(ProductWriter::class, $writer);
        $path = '/api/product-imports/'.$preview['id'];
        $this->postJson($path.'/process')->assertStatus(500);
        $this->assertSame(0, DB::table('products')->where('business_id', $session['business']['id'])->count());
        $this->getJson($path)->assertOk()->assertJsonPath('ready', 2)->assertJsonPath('imported', 0);
        $this->app->instance(ProductWriter::class, $original);
        $this->postJson($path.'/process')->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('status', 'completed');
        $this->assertSame(2, DB::table('stock_movements')->where('business_id', $session['business']['id'])->count());
    }
}
