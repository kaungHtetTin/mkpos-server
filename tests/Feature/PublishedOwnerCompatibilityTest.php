<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishedOwnerCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    public static function publishedClients(): array
    {
        return [
            'Windows 1.0.0 portable' => ['desktop', 'null'],
            'Android 1.0.1 APK' => ['android', 'https://localhost'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publishedClients')]
    public function test_published_token_clients_can_use_legacy_pos_payloads(string $client, string $origin): void
    {
        $this->withHeaders(['X-MKPOS-Auth' => 'token', 'X-MKPOS-Client' => $client, 'Origin' => $origin]);
        $email = Str::uuid().'@example.test';
        $registered = $this->postJson('/api/auth/register', [
            'business_name' => 'Published client fixture', 'owner_name' => 'Owner', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonStructure(['user', 'business', 'permissions', 'subscription', 'access_token', 'token_type']);
        $token = $registered->json('access_token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', $email);
        $this->getJson('/api/app-config')->assertOk()->assertJsonStructure(['enabled_modules', 'permissions']);

        // These clients send neither marketplace fields nor online descriptions.
        $product = $this->postJson('/api/products', [
            'name' => 'Legacy rice', 'category' => 'Own shelf', 'base_unit' => 'Piece',
            'price' => 1500, 'cost' => 900, 'stock' => 10, 'prices' => [['name' => 'Retail', 'price' => 1500]],
        ])->assertOk()->assertJsonPath('category', 'Own shelf')->json();
        $this->getJson('/api/products')->assertOk()->assertJsonPath('0.id', $product['id']);
        $this->getJson('/api/products?with_total=true&limit=20&offset=0')->assertOk()
            ->assertJsonStructure(['items', 'total', 'limit', 'offset'])->assertJsonPath('items.0.stock', 10);
        $this->putJson('/api/products/'.$product['id'], [
            'name' => 'Legacy rice edited', 'category' => 'Own shelf', 'price' => 1500, 'cost' => 900, 'stock' => 10,
        ])->assertOk()->assertJsonPath('name', 'Legacy rice edited');
        $sale = $this->postJson('/api/sales', [
            'payment_type' => 'cash', 'payment_method' => 'Cash', 'paid_amount' => 1500, 'discount' => 0,
            'items' => [['product_id' => $product['id'], 'price_type' => 'Retail', 'quantity' => 1, 'foc_quantity' => 0, 'unit_price' => 1500]],
        ])->assertOk()->assertJsonPath('total', 1500)->json();
        $this->getJson('/api/sales/'.$sale['id'].'/receipt')->assertOk()->assertJsonStructure(['sale', 'text', 'html']);
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user', 'business']);
    }

    public function test_pre_update_token_is_accepted_within_expiry_window_but_not_after_it(): void
    {
        $this->withHeader('X-MKPOS-Auth', 'token');
        $registered = $this->postJson('/api/auth/register', [
            'business_name' => 'Old token fixture', 'owner_name' => 'Owner', 'email' => Str::uuid().'@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated();
        $user = User::findOrFail($registered->json('user.id'));
        // Published backend issued tokens without an expires_at timestamp.
        $legacy = $user->createToken('mkpos-desktop', ['*']);
        $this->assertNull($legacy->accessToken->expires_at);
        config(['sanctum.expiration' => 43200]);
        DB::table('personal_access_tokens')->where('id', $legacy->accessToken->id)->update(['created_at' => now()->subDays(29)]);
        Auth::forgetGuards();
        $this->withToken($legacy->plainTextToken)->getJson('/api/auth/me')->assertOk();
        DB::table('personal_access_tokens')->where('id', $legacy->accessToken->id)->update(['created_at' => now()->subDays(31)]);
        Auth::forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
