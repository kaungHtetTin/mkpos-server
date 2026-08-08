<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MobileAuthApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mobile_dev_origin_receives_a_session_backed_auth_flow(): void
    {
        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5176',
            'Referer' => 'http://127.0.0.1:5176/signup',
        ]);

        $this->postJson('/api/auth/register', [
            'business_name' => 'Mobile Session Shop',
            'owner_name' => 'Mobile Owner',
            'email' => 'mobile-session@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'mobile-session@example.com');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('business.name', 'Mobile Session Shop');

        $this->postJson('/api/auth/logout')->assertOk();
    }

    public function test_portable_clients_can_use_the_same_auth_routes_with_a_bearer_token(): void
    {
        $headers = [
            'X-MKPOS-Auth' => 'token',
            'X-MKPOS-Client' => 'android',
        ];

        $register = $this->withHeaders($headers)->postJson('/api/auth/register', [
            'business_name' => 'Portable Token Shop',
            'owner_name' => 'Portable Owner',
            'email' => 'portable-token@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'portable-token@example.com')
            ->assertJsonPath('token_type', 'Bearer');

        $token = $register->json('access_token');
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('business.name', 'Portable Token Shop');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_wildcard_cors_reflects_arbitrary_origins_for_credentialed_clients(): void
    {
        $this->withHeaders([
            'Origin' => 'https://customer-deployment.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,x-mkpos-auth',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://customer-deployment.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
