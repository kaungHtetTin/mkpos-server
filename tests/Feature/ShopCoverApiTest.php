<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopCoverApiTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(): array
    {
        $this->withHeader('Origin', 'http://localhost')->withHeader('Accept', 'application/json');
        $session = $this->postJson('/api/auth/register', ['business_name' => 'Cover Shop', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated()->json();
        $this->postJson('/api/shop-admin/owner-location', ['latitude' => 16.84, 'longitude' => 96.17, 'accuracy' => 10])->assertOk();
        $settings = $this->getJson('/api/shop-admin/settings')->assertOk()->json();
        DB::table('storefront_settings')->where('business_id', $session['business']['id'])->update(['storefront_enabled' => true]);
        return $settings;
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII='));
    }

    public function test_cover_upload_replacement_public_serving_and_removal_do_not_change_the_logo(): void
    {
        Storage::fake('local');
        $settings = $this->owner();
        $this->post('/api/settings/logo', ['logo' => $this->image()])->assertOk();
        $logo = DB::table('settings')->where('business_id', $settings['business_id'])->where('key', 'receipt_logo_path')->value('value');
        $this->post('/api/shop-admin/cover', ['cover' => $this->image()])->assertOk()->assertJsonPath('cover_data_url', fn ($value) => str_starts_with($value, 'data:image/png;base64,'));
        $first = DB::table('settings')->where('business_id', $settings['business_id'])->where('key', 'shop_cover_path')->value('value');
        Storage::disk('local')->assertExists($first);
        $url = $this->getJson('/api/ecommerce/stores/'.$settings['slug'])->assertOk()->json('cover_url');
        $this->assertStringContainsString('/cover?v=', $url);
        $this->get('/api/ecommerce/stores/'.$settings['slug'].'/cover')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/shop-admin/cover', ['cover' => $this->image()])->assertOk();
        Storage::disk('local')->assertMissing($first);
        $second = DB::table('settings')->where('business_id', $settings['business_id'])->where('key', 'shop_cover_path')->value('value');
        Storage::disk('local')->assertExists($second);
        $this->assertSame($logo, DB::table('settings')->where('business_id', $settings['business_id'])->where('key', 'receipt_logo_path')->value('value'));
        $this->deleteJson('/api/shop-admin/cover')->assertOk()->assertJsonPath('cover_data_url', '');
        Storage::disk('local')->assertMissing($second);
        Storage::disk('local')->assertExists($logo);
        $this->get('/api/ecommerce/stores/'.$settings['slug'].'/cover')->assertNotFound();
    }

    public function test_covers_are_isolated_between_businesses_and_invalid_uploads_leave_the_current_cover_saved(): void
    {
        Storage::fake('local');
        $first = $this->owner();
        $this->post('/api/shop-admin/cover', ['cover' => $this->image()])->assertOk();
        $path = DB::table('settings')->where('business_id', $first['business_id'])->where('key', 'shop_cover_path')->value('value');
        $this->post('/api/shop-admin/cover', ['cover' => UploadedFile::fake()->create('invalid.txt', 1, 'text/plain')])->assertStatus(422);
        Storage::disk('local')->assertExists($path);
        $this->postJson('/api/auth/logout')->assertOk();
        app('auth')->forgetGuards();
        $this->post('/api/shop-admin/cover', ['cover' => $this->image()])->assertUnauthorized();
        $second = $this->owner();
        $this->getJson('/api/shop-admin/settings')->assertOk()->assertJsonPath('cover_data_url', '');
        $this->deleteJson('/api/shop-admin/cover')->assertOk();
        Storage::disk('local')->assertExists($path);
        $this->get('/api/ecommerce/stores/'.$second['slug'].'/cover')->assertNotFound();
        $this->get('/api/ecommerce/stores/'.$first['slug'].'/cover')->assertOk();
    }
}
