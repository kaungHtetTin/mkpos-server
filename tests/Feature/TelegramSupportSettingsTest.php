<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TelegramClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramSupportSettingsTest extends TestCase
{
    private const TOKEN = '123456:fixture_token_12345678901234567890';

    private const URL = 'https://example.test/integrations/telegram/customer-service';

    private const PATH = '/api/office/telegram-support';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.telegram_settings_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'telegram_support.enabled' => false, 'telegram_support.bot_token' => '', 'telegram_support.webhook_secret' => '']);
        DB::setDefaultConnection('telegram_settings_fixture');
        (require database_path('migrations/2026_10_26_000006_create_telegram_support_settings.php'))->up();
        foreach (['customer_service_messages', 'jobs'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
        Schema::create('office_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('action');
            $table->string('method');
            $table->string('path');
            $table->text('details');
            $table->timestamp('created_at');
        });
        Http::preventStrayRequests();
    }

    private function admin(string $role = 'super_admin'): void
    {
        $admin = new PlatformAdmin(['name' => 'Fixture admin', 'email' => 'fixture@example.test', 'is_active' => true, 'role' => $role]);
        $admin->id = 1;
        $this->actingAs($admin, 'office');
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['version' => 0, 'enabled' => true, 'webhook_url' => self::URL, 'daily_limit' => 200,
            'per_chat_daily_limit' => 20, 'bot_token' => self::TOKEN], $overrides);
    }

    private function telegram(string $current = ''): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => fn ($request) => Http::response(['ok' => true, 'result' => str_ends_with($request->url(), '/getMe') ? ['id' => 123456, 'is_bot' => true, 'username' => 'fixture_support_bot'] :
            (str_ends_with($request->url(), '/getWebhookInfo') ? ['url' => $current, 'pending_update_count' => 2] : true)])]);
    }

    public function test_only_super_admins_can_read_test_or_change_telegram_settings(): void
    {
        $this->getJson(self::PATH)->assertUnauthorized();
        $this->admin('admin');
        $this->getJson(self::PATH)->assertForbidden();
        $this->putJson(self::PATH, $this->data())->assertForbidden();
        $this->postJson(self::PATH.'/test-connection')->assertForbidden();
        $this->postJson(self::PATH.'/register-webhook', ['version' => 0])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_save_encrypts_credentials_keeps_responses_and_audits_private_and_makes_no_external_calls(): void
    {
        $this->admin();
        $response = $this->putJson(self::PATH, $this->data())->assertOk()->assertJsonPath('settings.token_configured', true)->assertJsonPath('settings.version', 1);
        $row = DB::table('telegram_support_settings')->first();
        $this->assertSame(self::TOKEN, Crypt::decryptString($row->bot_token_encrypted));
        $this->assertNotSame(self::TOKEN, $row->bot_token_encrypted);
        $secret = Crypt::decryptString($row->webhook_secret_encrypted);
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $public = $response->getContent().$this->getJson(self::PATH)->assertOk()->getContent().DB::table('office_activity_logs')->value('details');
        $this->assertStringNotContainsString(self::TOKEN, $public);
        $this->assertStringNotContainsString($secret, $public);
        $this->assertStringNotContainsString('bot_token', DB::table('office_activity_logs')->value('details'));
        Http::assertNothingSent();
    }

    public function test_blank_token_keeps_credentials_stale_versions_fail_and_running_services_read_office_changes(): void
    {
        $this->admin();
        $service = app(Settings::class);
        $this->assertFalse($service->get()['enabled']);
        $this->putJson(self::PATH, $this->data())->assertOk();
        $secret = $service->get()['webhook_secret'];
        $this->putJson(self::PATH, $this->data(['version' => 0, 'enabled' => false]))->assertStatus(409);
        $this->putJson(self::PATH, $this->data(['version' => 1, 'bot_token' => '', 'enabled' => false, 'daily_limit' => 10]))->assertOk();
        $this->assertFalse($service->get()['enabled']);
        $this->assertSame(10, $service->get()['daily_limit']);
        $this->assertSame(self::TOKEN, $service->get()['bot_token']);
        $this->assertSame($secret, $service->get()['webhook_secret']);
        Http::assertNothingSent();
    }

    public function test_test_connection_can_use_a_draft_token_without_saving_or_registering_or_sending_messages(): void
    {
        $this->admin();
        $this->telegram();
        $this->postJson(self::PATH.'/test-connection', ['bot_token' => self::TOKEN])->assertOk()
            ->assertJsonPath('connection.bot_username', 'fixture_support_bot')->assertJsonPath('connection.webhook_status', 'not_registered');
        $this->assertFalse(app(Settings::class)->publicData()['token_configured']);
        $this->assertSame(0, app(Settings::class)->get()['version']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') || str_ends_with($request->url(), '/sendMessage'));
        $this->assertStringNotContainsString(self::TOKEN, DB::table('office_activity_logs')->value('details'));
    }

    public function test_registration_is_explicit_uses_saved_settings_and_preserves_a_different_webhook_unless_replaced(): void
    {
        $this->admin();
        $this->putJson(self::PATH, $this->data())->assertOk();
        $this->telegram('https://other.example.test/existing');
        $this->postJson(self::PATH.'/register-webhook', ['version' => 1])->assertStatus(409);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/setWebhook'));
        $this->postJson(self::PATH.'/register-webhook', ['version' => 1, 'replace_existing' => true])->assertOk()
            ->assertJsonPath('settings.version', 2)->assertJsonPath('settings.bot_username', 'fixture_support_bot');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === self::URL
            && $request['drop_pending_updates'] === false && strlen($request['secret_token']) >= 32);
        $this->postJson(self::PATH.'/register-webhook', ['version' => 1])->assertStatus(409);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_changing_bot_rotates_webhook_identity_and_old_replies_cannot_be_sent_by_the_new_bot(): void
    {
        $this->admin();
        $this->putJson(self::PATH, $this->data())->assertOk();
        $secret = app(Settings::class)->get()['webhook_secret'];
        $this->putJson(self::PATH, $this->data(['version' => 1, 'bot_token' => '654321:replacement_token_12345678901234567890']))->assertOk();
        $this->assertNotSame($secret, app(Settings::class)->get()['webhook_secret']);
        $this->assertSame(['ok' => false, 'error' => 'bot_changed'], app(TelegramClient::class)->reply(9001, 1, 'Old answer', 123456));
        Http::assertNothingSent();
    }

    public function test_validation_and_corrupt_saved_secrets_fail_closed(): void
    {
        $this->admin();
        foreach (['http://localhost/integrations/telegram/customer-service', 'https://user:password@example.test/integrations/telegram/customer-service', self::URL.'?token=secret'] as $url) {
            $this->putJson(self::PATH, $this->data(['webhook_url' => $url]))->assertUnprocessable();
        }
        $this->putJson(self::PATH, $this->data(['bot_token' => 'invalid']))->assertUnprocessable();
        $this->putJson(self::PATH, $this->data(['daily_limit' => 0]))->assertUnprocessable();
        $this->putJson(self::PATH, $this->data())->assertOk();
        config(['telegram_support.bot_token' => self::TOKEN]);
        DB::table('telegram_support_settings')->update(['bot_token_encrypted' => 'corrupt']);
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('settings.token_configured', false);
        $this->assertNull(app(Settings::class)->get()['bot_token']);
        Http::assertNothingSent();
    }
}
