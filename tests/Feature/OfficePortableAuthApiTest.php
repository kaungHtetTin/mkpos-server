<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OfficePortableAuthApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_production_office_origin_receives_session_middleware(): void
    {
        PlatformAdmin::create([
            'name' => 'Production Platform Owner',
            'email' => 'production-office@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $login = $this->withHeaders([
            'Origin' => 'https://www.mkposmyanmar.com',
            'Referer' => 'https://www.mkposmyanmar.com/public/office/',
        ])->postJson('/api/office/auth/login', [
            'email' => 'production-office@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('admin.email', 'production-office@example.com');

        $login->assertJsonMissing(['access_token']);
        $this->getJson('/api/office/auth/me')
            ->assertOk()
            ->assertJsonPath('admin.email', 'production-office@example.com');
    }

    public function test_portable_office_can_login_use_and_revoke_an_admin_token(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Portable Platform Owner',
            'email' => 'portable-office@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $login = $this->withHeaders([
            'Origin' => 'https://www.mkposmyanmar.com',
            'Referer' => 'https://www.mkposmyanmar.com/mkpos-office/',
            'X-MKPOS-Auth' => 'token',
            'X-MKPOS-Client' => 'office',
        ])->postJson('/api/office/auth/login', [
            'email' => 'portable-office@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('admin.email', 'portable-office@example.com')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token']);

        $token = $login->json('access_token');
        $this->withToken($token)->getJson('/api/office/auth/me')
            ->assertOk()
            ->assertJsonPath('admin.name', 'Portable Platform Owner');
        $this->withToken($token)->getJson('/api/office/plans')->assertOk();

        $this->withToken($token)->postJson('/api/office/auth/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => PlatformAdmin::class,
            'tokenable_id' => $admin->id,
        ]);
        $this->withToken($token)->getJson('/api/office/auth/me')->assertUnauthorized();
    }

    public function test_inactive_admin_cannot_create_a_portable_office_token(): void
    {
        PlatformAdmin::create([
            'name' => 'Inactive Platform Owner',
            'email' => 'inactive-office@example.com',
            'password' => Hash::make('password123'),
            'is_active' => false,
        ]);

        $this->withHeaders(['X-MKPOS-Auth' => 'token'])
            ->postJson('/api/office/auth/login', [
                'email' => 'inactive-office@example.com',
                'password' => 'password123',
            ])->assertUnprocessable();
    }

    public function test_explicit_office_token_mode_never_uses_an_admin_session(): void
    {
        $admin = PlatformAdmin::create([
            'name' => 'Session Platform Owner',
            'email' => 'office-session-isolation@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'office')
            ->withHeader('X-MKPOS-Auth', 'token')
            ->getJson('/api/office/auth/me')
            ->assertUnauthorized();
    }
}
