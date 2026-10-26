<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\AiAssistantSettings;
use App\Services\AiProviderCredentials;
use App\Services\ServerHealth;
use App\Services\WorkerHealth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServerHealthTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = storage_path('framework/testing/server-health-'.Str::uuid());
        config(['database.connections.health_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'health_fixture', 'cache.stores.health_fixture' => ['driver' => 'file', 'path' => $this->cachePath],
            'telegram_support.enabled' => false, 'mcp_payments.token' => '', 'shop_chat.realtime_enabled' => false,
            'mail.mailers.smtp' => ['host' => 'smtp.fixture.test', 'port' => 587, 'username' => 'fixture', 'password' => 'SENSITIVE_PASSWORD', 'encryption' => 'tls'],
            'mail.from.address' => 'support@fixture.test', 'mail.actions' => [], 'session.driver' => 'file']);
        DB::setDefaultConnection('health_fixture');
        Schema::create('mcp_support_tokens', fn (Blueprint $table) => $table->id());
        Schema::create('migrations', function (Blueprint $table) {
            $table->id();
            $table->string('migration');
        });
        foreach (glob(database_path('migrations/*.php')) as $file) {
            DB::table('migrations')->insert(['migration' => basename($file, '.php')]);
        }
        Schema::create('ai_balance_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('balance_nanos');
        });
        DB::table('ai_balance_accounts')->insert(['id' => 1, 'balance_nanos' => 1000]);
        $this->mock(AiAssistantSettings::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['enabled' => true, 'model' => 'deepseek-flash', 'usd_to_mmk_rate' => '4200', 'sales_ai_markup_percent' => '5.00']));
        $this->mock(AiProviderCredentials::class, fn ($mock) => $mock->shouldReceive('key')->andReturn('SENSITIVE_AI_KEY'));
        $this->mock(WorkerHealth::class, fn ($mock) => $mock->shouldReceive('snapshot')->andReturn(['status' => 'healthy', 'workers' => [
            'sales_ai' => ['required' => true, 'status' => 'healthy', 'last_seen_at' => now()->toIso8601String()],
            'wyne_wyne' => ['required' => true, 'status' => 'healthy'], 'scheduler' => ['required' => true, 'status' => 'healthy'],
        ]]));
        Cache::put('ai.deepseek.pricing.v2', ['fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => .3, 'output' => 1.2, 'cached_input' => .006]]], 3600);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->cachePath) && str_starts_with($this->cachePath, storage_path('framework/testing/server-health-'))) {
            (new Filesystem)->deleteDirectory($this->cachePath);
        }
        parent::tearDown();
    }

    private function admin(string $role): void
    {
        $admin = new PlatformAdmin(['name' => 'Fixture', 'email' => 'admin@fixture.test', 'role' => $role, 'is_active' => true]);
        $admin->id = 1;
        $this->actingAs($admin, 'office');
    }

    private function checks(): Collection
    {
        return collect(app(ServerHealth::class)->snapshot()['checks'])->keyBy('id');
    }

    public function test_only_super_admins_can_read_diagnostics_and_results_are_not_cached_by_the_browser(): void
    {
        $this->getJson('/api/office/health/system')->assertUnauthorized();
        $this->admin('admin');
        $this->getJson('/api/office/health/system')->assertForbidden();
        $this->admin('super_admin');
        $response = $this->getJson('/api/office/health/system')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(33, $response->json('checks'));
        $this->assertSame(33, array_sum($response->json('counts')));
        $response->assertDontSee('SENSITIVE_AI_KEY')->assertDontSee('SENSITIVE_PASSWORD')->assertDontSee('smtp.fixture.test');
        Http::assertNothingSent();
    }

    public function test_live_cache_database_heartbeat_pricing_and_mcp_checks_do_not_send_messages_or_scrape_prices(): void
    {
        $checks = $this->checks();
        foreach (['database', 'cache', 'migrations', 'worker_sales_ai', 'worker_scheduler', 'ai_key', 'ai_credit', 'ai_rates', 'mcp', 'assets_office'] as $id) {
            $this->assertSame('healthy', $checks[$id]['status'], $id);
        }
        $this->assertSame('disabled', $checks['telegram']['status']);
        $this->assertSame('configuration', $checks['mail_auth']['scope']);
        $this->assertStringContainsString('not been tested', $checks['mail_auth']['message']);
        $this->assertCount(1, (new Filesystem)->allFiles($this->cachePath), 'Probe cache key must be removed; only saved pricing remains.');
        Http::assertNothingSent();
    }

    public function test_failures_are_isolated_and_missing_migrations_empty_credit_and_nonpersistent_cache_are_reported(): void
    {
        DB::table('migrations')->limit(1)->delete();
        DB::table('ai_balance_accounts')->update(['balance_nanos' => 0]);
        config(['cache.default' => 'array', 'session.driver' => 'array']);
        $checks = $this->checks();
        foreach (['migrations', 'ai_credit', 'cache', 'sessions', 'ai_rates'] as $id) {
            $this->assertSame('unhealthy', $checks[$id]['status'], $id);
        }
        $this->assertSame('healthy', $checks['database']['status']);
        $this->assertSame('healthy', $checks['mcp']['status']);
        Http::assertNothingSent();
    }

    public function test_realtime_probe_is_bounded_to_the_configured_service_and_never_sends_signing_credentials(): void
    {
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('secret', 8), 'shop_chat.publish_host' => '127.0.0.1', 'shop_chat.publish_port' => 8080, 'shop_chat.publish_scheme' => 'http']);
        Http::fake(['http://127.0.0.1:8080/health' => Http::response(['ok' => true, 'service' => 'mkpos-chat-realtime'])]);
        $this->assertSame('healthy', $this->checks()['socket']['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Chat-Signature'));
    }

    public function test_integration_errors_do_not_leak_urls_credentials_or_raw_exception_text(): void
    {
        config(['shop_chat.realtime_enabled' => true, 'shop_chat.secret' => str_repeat('secret', 8), 'shop_chat.publish_host' => '127.0.0.1', 'shop_chat.publish_port' => 8080, 'shop_chat.publish_scheme' => 'http']);
        Http::fake(fn () => throw new \RuntimeException('SENSITIVE_SOCKET_EXCEPTION'));
        $snapshot = app(ServerHealth::class)->snapshot();
        $this->assertSame('unhealthy', collect($snapshot['checks'])->keyBy('id')['socket']['status']);
        $this->assertStringNotContainsString('SENSITIVE_SOCKET_EXCEPTION', json_encode($snapshot));
        $this->assertStringNotContainsString('127.0.0.1', json_encode($snapshot));
    }

    public function test_optional_telegram_becomes_required_when_enabled_and_does_not_test_or_register_remotely(): void
    {
        config(['telegram_support.enabled' => true, 'telegram_support.bot_token' => '123456:SENSITIVE_TELEGRAM_TOKEN_123456789',
            'telegram_support.webhook_secret' => str_repeat('secret', 8), 'telegram_support.webhook_url' => 'https://fixture.test/integrations/telegram/customer-service']);
        $check = $this->checks()['telegram'];
        $this->assertTrue($check['required']);
        $this->assertSame('warning', $check['status']);
        $this->assertStringNotContainsString('SENSITIVE_TELEGRAM_TOKEN', json_encode($check));
        Http::assertNothingSent();
    }

    public function test_monitor_failure_and_stale_pricing_do_not_masquerade_as_healthy(): void
    {
        $this->mock(WorkerHealth::class, fn ($mock) => $mock->shouldReceive('snapshot')->andThrow(new \RuntimeException('SENSITIVE_DB_ERROR')));
        $snapshot = Cache::get('ai.deepseek.pricing.v2');
        $snapshot['fetched_at'] = now()->subDay()->toIso8601String();
        Cache::put('ai.deepseek.pricing.v2', $snapshot, 3600);
        $checks = $this->checks();
        $this->assertSame('unhealthy', $checks['workers']['status']);
        $this->assertSame('warning', $checks['ai_rates']['status']);
        $this->assertSame('healthy', $checks['database']['status']);
        $this->assertStringNotContainsString('SENSITIVE_DB_ERROR', json_encode($checks));
    }
}
