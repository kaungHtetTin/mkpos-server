<?php

namespace Tests\Feature;

use App\Ai\Agents\CustomerServiceAgent;
use App\Ai\Tools\CustomerServiceKnowledge;
use App\Services\AiAssistantSettings;
use App\Services\AiUsageReport;
use App\Services\TelegramSupport\Inbox;
use App\Services\TelegramSupport\Runner;
use App\Services\TelegramSupport\Settings;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\Request as ToolRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TelegramCustomerServiceTest extends TestCase
{
    private const SECRET = 'fixture_webhook_secret_12345678901234567890';

    private const TOKEN = '123456:fixture_token_12345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.telegram_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'telegram_support.enabled' => true, 'telegram_support.bot_token' => self::TOKEN,
            'telegram_support.webhook_secret' => self::SECRET, 'telegram_support.daily_limit' => 200,
            'queue.connections.customer-service.connection' => 'telegram_fixture', 'queue.connections.customer-service.after_commit' => false,
            'ai.providers.deepseek.key' => 'fixture-key-never-return']);
        DB::setDefaultConnection('telegram_fixture');
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->string('model');
            $table->string('status');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('ai_messages', fn (Blueprint $table) => $table->id());
        foreach (['2026_10_26_000005_create_customer_service_agent.php', '2026_10_26_000006_create_telegram_support_settings.php', '2026_10_25_000002_create_ai_balance_book_tables.php', '2026_10_25_000003_add_cached_input_tokens_to_ai_usage.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 10_000_000_000]);
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $this->mock(AiAssistantSettings::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['enabled' => true, 'configured' => true]));
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => 'fixture', 'fetched_at' => now()->toIso8601String(),
            'rates' => ['deepseek-flash' => ['input' => 0.3, 'output' => 1.2, 'cached_input' => 0.006]]], 3600);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);
        CustomerServiceAgent::fake(['MKPOS helps you manage your shop.']);
    }

    private function update(string $text = 'What is MKPOS?', int $update = 1, int $chat = 9001): array
    {
        return ['update_id' => $update, 'message' => ['message_id' => $update, 'text' => $text,
            'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'is_bot' => false, 'language_code' => 'en']]];
    }

    private function receive(array $update)
    {
        return $this->postJson('/integrations/telegram/customer-service', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function id(int $update = 1): string
    {
        return DB::table('customer_service_messages')->where('update_id', $update)->value('id');
    }

    public function test_webhook_requires_the_secret_and_does_not_invoke_a_model_or_send_a_reply_in_http(): void
    {
        $this->postJson('/integrations/telegram/customer-service', $this->update())->assertForbidden();
        $this->assertSame(0, DB::table('customer_service_messages')->count());
        $this->receive($this->update())->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('customer-service', DB::table('jobs')->value('queue'));
        $this->assertStringNotContainsString('What is MKPOS?', DB::table('jobs')->value('payload'));
        CustomerServiceAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    private function officeSettings(array $overrides = []): void
    {
        app(Settings::class)->save(array_replace([
            'version' => app(Settings::class)->get()['version'], 'enabled' => true,
            'webhook_url' => 'https://example.test/integrations/telegram/customer-service', 'daily_limit' => 200, 'per_chat_daily_limit' => 20,
        ], $overrides));
    }

    public function test_office_disable_and_reenable_are_seen_by_the_same_running_worker(): void
    {
        $this->receive($this->update())->assertOk();
        $worker = app(Runner::class);
        $this->officeSettings(['enabled' => false]);
        $this->assertSame(60, $worker->process($this->id()));
        CustomerServiceAgent::assertNeverPrompted();
        Http::assertNothingSent();
        $this->officeSettings(['enabled' => true]);
        $worker->process($this->id());
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        CustomerServiceAgent::assertPromptedTimes(1);
    }

    public function test_office_bot_change_rejects_old_webhook_and_cancels_old_queued_work(): void
    {
        $this->receive($this->update())->assertOk();
        $this->officeSettings(['bot_token' => '654321:replacement_token_12345678901234567890']);
        $this->receive($this->update('Old bot', 2))->assertForbidden();
        app(Runner::class)->process($this->id());
        $this->assertSame('cancelled', DB::table('customer_service_messages')->value('status'));
        CustomerServiceAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_webhook_admission_uses_office_limits_instead_of_cached_environment_limits(): void
    {
        $this->officeSettings(['daily_limit' => 1]);
        $this->receive($this->update())->assertOk();
        $this->receive($this->update('Another question', 2, 9002))->assertOk();
        $this->assertSame(1, DB::table('customer_service_messages')->where('uses_ai', true)->count());
        $this->assertSame(1, DB::table('customer_service_budget_days')->value('requests'));
        Http::assertNothingSent();
    }

    public function test_duplicate_update_is_accepted_once_and_text_is_encrypted_at_rest(): void
    {
        $this->receive($this->update())->assertOk();
        $this->receive($this->update())->assertOk();
        $this->assertSame(1, DB::table('customer_service_messages')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('customer_service_budget_days')->value('requests'));
        $input = DB::table('customer_service_messages')->value('input');
        $this->assertStringNotContainsString('What is MKPOS?', $input);
        $this->assertSame('What is MKPOS?', Crypt::decryptString($input));
    }

    public function test_group_messages_bot_senders_and_mismatched_private_identity_are_ignored(): void
    {
        $update = $this->update();
        $update['message']['chat']['type'] = 'group';
        $this->receive($update)->assertOk();
        $update = $this->update();
        $update['message']['from']['is_bot'] = true;
        $this->receive($update)->assertOk();
        $update = $this->update();
        $update['message']['from']['id'] = 1234;
        $this->receive($update)->assertOk();
        $this->receive(['update_id' => 5, 'edited_message' => $this->update()['message']])->assertOk();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_worker_executes_the_model_and_bills_once_despite_job_redelivery(): void
    {
        $this->receive($this->update())->assertOk();
        $queue = Queue::connection('customer-service');
        $job = $queue->pop('customer-service');
        $job->fire();
        app(Runner::class)->process($this->id());
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        $this->assertSame('Telegram customer service AI', DB::table('ai_balance_entries')->value('description'));
        CustomerServiceAgent::assertPromptedTimes(1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === 9001 && $request['text'] === 'MKPOS helps you manage your shop.' && ! isset($request['parse_mode']));
    }

    public function test_commands_and_unsupported_media_do_not_use_ai_credit(): void
    {
        $this->receive($this->update('/start'))->assertOk();
        app(Runner::class)->process($this->id());
        $media = $this->update('', 2);
        unset($media['message']['text']);
        $media['message']['photo'] = [['file_id' => 'never-download']];
        $this->receive($media)->assertOk();
        app(Runner::class)->process($this->id(2));
        $this->assertSame(0, DB::table('ai_balance_entries')->count());
        CustomerServiceAgent::assertNeverPrompted();
        Http::assertSentCount(2);
    }

    public function test_daily_cap_is_admitted_atomically_and_reset_does_not_reset_the_quota(): void
    {
        config(['telegram_support.daily_limit' => 1]);
        $this->receive($this->update())->assertOk();
        $this->receive($this->update('Another question', 2, 9002))->assertOk();
        $this->assertFalse((bool) DB::table('customer_service_messages')->where('update_id', 2)->value('uses_ai'));
        $this->receive($this->update('/reset', 3))->assertOk();
        $this->receive($this->update('Try again', 4))->assertOk();
        $this->assertFalse((bool) DB::table('customer_service_messages')->where('update_id', 4)->value('uses_ai'));
        $this->assertSame(1, DB::table('customer_service_budget_days')->value('requests'));
    }

    public function test_each_chat_has_its_own_history_and_no_tenant_identity_is_inherited(): void
    {
        $this->receive($this->update('CHAT_ONE_PRIVATE_QUESTION'))->assertOk();
        app(Runner::class)->process($this->id());
        $this->receive($this->update('Explain packages', 2, 9002))->assertOk();
        app(TenantContext::class)->set(789);
        CustomerServiceAgent::fake(function () {
            $this->assertNull(app(TenantContext::class)->id());

            return 'Public package explanation';
        });
        app(Runner::class)->process($this->id(2));
        CustomerServiceAgent::assertPrompted(function (AgentPrompt $prompt) {
            $this->assertSame([], $prompt->agent->history);

            return true;
        });
        $this->assertSame(789, app(TenantContext::class)->id());
        Http::assertSent(fn ($request) => $request['chat_id'] === 9002 && $request['text'] === 'Public package explanation');
    }

    public function test_reset_suppresses_an_in_flight_reply_but_records_incurred_usage(): void
    {
        $this->receive($this->update())->assertOk();
        CustomerServiceAgent::fake(function () {
            app(Inbox::class)->accept($this->update('/reset', 2));

            return 'Answer from old history';
        });
        app(Runner::class)->process($this->id());
        $row = DB::table('customer_service_messages')->where('update_id', 1)->first();
        $this->assertSame('cancelled', $row->status);
        $this->assertNull($row->input);
        $this->assertNull($row->reply);
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        Http::assertNothingSent();
    }

    public function test_telegram_rate_limit_retries_only_the_saved_answer_without_another_model_call(): void
    {
        $this->receive($this->update())->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 2]], 429)
            ->push(['ok' => true, 'result' => ['message_id' => 502]]);
        $this->assertSame(2, app(Runner::class)->process($this->id()));
        $this->assertSame('ready', DB::table('customer_service_messages')->value('status'));
        $this->travel(3)->seconds();
        app(Runner::class)->process($this->id());
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        CustomerServiceAgent::assertPromptedTimes(1);
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
    }

    public function test_ambiguous_delivery_is_never_automatically_sent_again(): void
    {
        $this->receive($this->update())->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => function () {
            throw new \RuntimeException('Fixture connection interruption');
        }]);
        app(Runner::class)->process($this->id());
        app(Runner::class)->process($this->id());
        $this->assertSame('delivery_unknown', DB::table('customer_service_messages')->value('status'));
        CustomerServiceAgent::assertPromptedTimes(1);
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
    }

    public function test_interrupted_processing_is_not_replayed_and_expired_text_is_erased(): void
    {
        $this->receive($this->update())->assertOk();
        DB::table('customer_service_messages')->update(['status' => 'processing', 'started_at' => now('UTC')->subMinutes(11)]);
        $this->artisan('customer-service:recover')->assertSuccessful();
        app(Runner::class)->process($this->id());
        $this->assertSame('failed', DB::table('customer_service_messages')->value('status'));
        DB::table('customer_service_messages')->update(['created_at' => now('UTC')->subDays(31)]);
        $this->artisan('customer-service:prune')->assertSuccessful();
        $this->assertNull(DB::table('customer_service_messages')->value('input'));
        CustomerServiceAgent::assertNeverPrompted();
    }

    public function test_public_agent_tools_cannot_acquire_owner_or_admin_capabilities(): void
    {
        $agent = new CustomerServiceAgent;
        $names = array_map(fn ($tool) => $tool->name(), iterator_to_array($agent->tools()));
        $this->assertContains('get_mkpos_overview', $names);
        $this->assertContains('get_available_package_plans', $names);
        foreach (['get_my_profile', 'get_my_billing_history', 'approve_subscription_payment', 'submit_subscription_payment_proof', 'get_business_summary', 'request_refund'] as $forbidden) {
            $this->assertNotContains($forbidden, $names);
        }
        $overview = json_decode((new CustomerServiceKnowledge($agent, 'get_mkpos_overview'))->handle(new ToolRequest([])), true);
        $this->assertSame('MKPOS', $overview['product']);
        $account = json_decode((new CustomerServiceKnowledge($agent, 'get_account_guide'))->handle(new ToolRequest(['operation' => 'register'])), true);
        $this->assertArrayNotHasKey('error', $account);
        $this->expectException(HttpException::class);
        new CustomerServiceKnowledge($agent, 'approve_subscription_payment');
    }

    public function test_disabled_bot_rejects_requests_without_queuing_work(): void
    {
        config(['telegram_support.enabled' => false]);
        $this->receive($this->update())->assertNotFound();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_setup_checks_the_bot_without_registering_until_explicitly_requested(): void
    {
        config(['telegram_support.webhook_url' => 'https://example.test/integrations/telegram/customer-service']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => fn ($request) => Http::response(['ok' => true, 'result' => str_ends_with($request->url(), '/getMe')
            ? ['id' => 123456, 'is_bot' => true, 'username' => 'fixture_support_bot'] : (str_ends_with($request->url(), '/getWebhookInfo') ? ['url' => '', 'pending_update_count' => 0] : true)])]);
        $this->artisan('telegram-support:setup')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/setWebhook'));
        $this->artisan('telegram-support:setup', ['--register' => true])->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['secret_token'] === self::SECRET
            && $request['allowed_updates'] === ['message'] && $request['max_connections'] === 1 && $request['drop_pending_updates'] === false);
    }

    public function test_setup_preserves_a_different_existing_webhook(): void
    {
        config(['telegram_support.webhook_url' => 'https://example.test/integrations/telegram/customer-service']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => fn ($request) => Http::response(['ok' => true, 'result' => str_ends_with($request->url(), '/getMe')
            ? ['id' => 123456, 'is_bot' => true, 'username' => 'fixture_support_bot'] : ['url' => 'https://other.example.test/current-bot']])]);
        $this->artisan('telegram-support:setup', ['--register' => true])->assertFailed();
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/setWebhook'));
    }

    public function test_context_is_restored_only_for_the_same_chat_and_reported_usage_reaches_the_balance_book(): void
    {
        $answer = new AgentResponse('fixture-invocation', 'MKPOS helps your shop.',
            new TextUsage(inputTokens: 1000, outputTokens: 200), new Meta);
        CustomerServiceAgent::fake([$answer]);
        $this->receive($this->update('MY_PREVIOUS_QUESTION'))->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertLessThan(0, DB::table('ai_balance_entries')->value('amount_nanos'));
        $this->assertSame(1000, DB::table('customer_service_messages')->value('input_tokens'));
        $this->receive($this->update('Explain more', 2))->assertOk();
        CustomerServiceAgent::fake(['More public help']);
        app(Runner::class)->process($this->id(2));
        CustomerServiceAgent::assertPrompted(fn (AgentPrompt $prompt) => count($prompt->agent->history) === 2 && $prompt->agent->history[0]->content === 'MY_PREVIOUS_QUESTION');
        $report = app(AiUsageReport::class)->charts();
        $this->assertSame(2, array_sum(array_column($report['series']['hourly'], 'requests')));
    }

    public function test_bot_block_and_invalid_webhook_payloads_do_not_repeat_model_calls(): void
    {
        $this->receive(['update_id' => 'invalid'])->assertStatus(400);
        $this->receive($this->update(str_repeat('x', 66000)))->assertStatus(413);
        $this->receive($this->update())->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403], 403)]);
        app(Runner::class)->process($this->id());
        app(Runner::class)->process($this->id());
        $this->assertSame('failed', DB::table('customer_service_messages')->value('status'));
        Http::assertSentCount(1);
        CustomerServiceAgent::assertPromptedTimes(1);
    }
}
