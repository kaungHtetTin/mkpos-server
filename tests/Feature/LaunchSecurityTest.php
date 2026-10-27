<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchSecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cli_password_reset_revokes_existing_tokens_and_cookie_session(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Reset fixture', 'email' => Str::uuid().'@example.test',
            'password' => Hash::make('before-password'), 'role' => 'admin', 'is_active' => true]);
        $token = $admin->createToken('old-office-token', ['office'])->plainTextToken;
        $this->postJson('/api/office/auth/login', ['email' => $admin->email, 'password' => 'before-password'])->assertOk();
        $this->artisan('office:create-admin', ['name' => $admin->name, 'email' => $admin->email, '--password' => 'after-password'])->assertSuccessful();
        $this->assertNotNull($admin->fresh()->password_reset_at);
        $this->assertSame(0, $admin->tokens()->count());
        Auth::forgetGuards();
        $this->getJson('/api/office/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withHeaders(['X-MKPOS-Auth' => 'token'])->withToken($token)->getJson('/api/office/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/office/auth/login', ['email' => $admin->email, 'password' => 'after-password'])->assertOk();
    }

    public function test_production_seeding_cannot_create_a_predictable_admin(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('office:create-admin');
        (new \Database\Seeders\DatabaseSeeder)->run();
    }

    public function test_mcp_rejects_unlisted_origins_before_authentication_and_preflight(): void
    {
        config(['cors.allowed_origins' => ['https://office.example.test']]);
        foreach (['/mcp/knowledge', '/mcp/admin', '/mcp/account', '/mcp/system-agent', '/mcp/evidence', '/mcp/subscription-payments'] as $path) {
            $this->postJson($path, [], ['Origin' => 'https://untrusted.example'])->assertForbidden();
            $this->options($path, [], ['Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'POST'])->assertForbidden();
        }
        $this->postJson('/mcp/admin', [], ['Origin' => 'https://office.example.test'])->assertUnauthorized();
        $this->postJson('/mcp/admin', [], ['Origin' => ['https://office.example.test', 'https://untrusted.example']])->assertForbidden();
        $this->postJson('/mcp/admin')->assertUnauthorized();
        $this->options('/mcp/admin', [], ['Origin' => 'https://office.example.test', 'Access-Control-Request-Method' => 'POST'])
            ->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', 'https://office.example.test');
    }
}
