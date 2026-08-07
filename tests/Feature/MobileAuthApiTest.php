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
}
