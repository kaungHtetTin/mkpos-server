<?php

namespace Tests\Feature;

use App\Ai\Agents\CustomerServiceAgent;
use App\Ai\Agents\TelegramImageReader;
use App\Ai\Tools\CustomerServiceKnowledge;
use App\Ai\Tools\CustomerServiceErrorReport;
use App\Ai\Tools\CustomerServiceSkills;
use App\Http\Client\RefreshTelegramTyping;
use App\Models\PlatformAdmin;
use App\Services\AiAssistantSettings;
use App\Services\AiImagePolicy;
use App\Services\AiUsageReport;
use App\Services\TelegramSupport\Groups;
use App\Services\TelegramSupport\Inbox;
use App\Services\TelegramSupport\ReceiptNotices;
use App\Services\TelegramSupport\Runner;
use App\Services\TelegramSupport\Settings;
use App\Services\TelegramSupport\TypingIndicator;
use App\Support\AiLocale;
use App\Tenancy\TenantContext;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
        (require database_path('migrations/2026_10_28_000006_create_agent_skill_library.php'))->up();
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->string('model');
            $table->string('status');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->string('bank');
            $table->string('account_name');
            $table->string('account_no');
        });
        Schema::create('ai_messages', fn (Blueprint $table) => $table->id());
        foreach (['2026_10_26_000005_create_customer_service_agent.php', '2026_10_26_000006_create_telegram_support_settings.php', '2026_10_26_000008_add_telegram_support_groups.php', '2026_10_27_000001_telegram_images_and_receipt_notices.php', '2026_10_25_000002_create_ai_balance_book_tables.php', '2026_10_25_000003_add_cached_input_tokens_to_ai_usage.php'] as $file) {
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

    public function test_group_diagnostics_identify_missing_username_and_trigger_without_storing_message_text(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        DB::table('telegram_support_settings')->update(['bot_username' => null]);
        $this->receive($this->groupUpdate(2))->assertOk();
        $group = DB::table('telegram_support_groups')->first();
        $this->assertSame('bot_username_missing', app(Groups::class)->diagnostic($group)['reason']);
        $admin = new PlatformAdmin(['name' => 'Fixture', 'email' => 'fixture@example.test', 'is_active' => true, 'role' => 'super_admin']);
        $admin->id = 1;
        $this->actingAs($admin, 'office');
        $response = $this->getJson('/api/office/telegram-support/groups')->assertOk()->assertJsonPath('groups.0.reply_blocker', 'bot_username_missing')
            ->assertJsonPath('groups.0.last_update.reason', 'bot_username_missing');
        $this->assertStringNotContainsString('What is MKPOS?', $response->getContent());
        DB::table('telegram_support_settings')->update(['bot_username' => 'fixture_support_bot']);
        $ordinary = $this->groupUpdate(3);
        unset($ordinary['message']['entities']);
        $this->receive($ordinary)->assertOk();
        $this->assertSame('no_matching_trigger', app(Groups::class)->diagnostic($group)['reason']);
        $ordinary['update_id'] = 4;
        $ordinary['message']['from']['is_bot'] = true;
        $this->receive($ordinary)->assertOk();
        $this->assertSame('bot_or_anonymous_sender', app(Groups::class)->diagnostic($group)['reason']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_group_diagnostics_distinguish_disabled_group_replies_from_accepted_help(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $group = DB::table('telegram_support_groups')->first();
        DB::table('telegram_support_settings')->update(['groups_enabled' => false]);
        $this->receive($this->groupUpdate(2))->assertOk();
        $this->assertSame('group_replies_disabled', app(Groups::class)->diagnostic($group)['reason']);
        $this->approveGroups();
        $help = $this->groupUpdate(3);
        $help['message']['text'] = '/help@fixture_support_bot';
        $help['message']['entities'] = [['type' => 'bot_command', 'offset' => 0, 'length' => 25]];
        $this->receive($help)->assertOk();
        $this->assertSame('notice_queued', app(Groups::class)->diagnostic($group)['reason']);
        app(Runner::class)->process($this->id(3));
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        CustomerServiceAgent::assertNeverPrompted();
    }

    private function photoUpdate(int $update = 1, bool $document = false): array
    {
        $value = $this->update('', $update);
        unset($value['message']['text']);
        $image = ['file_id' => 'fixture_image_file_id', 'file_unique_id' => 'fixture_unique', 'file_size' => 1000];
        $value['message'][$document ? 'document' : 'photo'] = $document ? $image + ['mime_type' => 'image/png', 'file_name' => 'receipt.png'] : [$image + ['width' => 20, 'height' => 20]];

        return $value;
    }

    private function imageFixture(bool $notices = true, string $kind = 'receipt'): array
    {
        DB::table('payment_methods')->insertOrIgnore(['id' => 1, 'is_active' => true, 'bank' => 'Fixture Pay', 'account_name' => 'Fixture merchant', 'account_no' => '091234']);
        DB::table('telegram_support_settings')->where('id', 1)->update(['images_enabled' => true, 'receipt_notices_enabled' => $notices,
            'admin_group_chat_id' => -100777, 'admin_group_thread_id' => 77]);
        DB::table('telegram_support_groups')->insertOrIgnore(['bot_id' => 123456, 'chat_id' => -100777, 'title' => 'Private admin group', 'is_member' => true]);
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/fixture.png', 'file_size' => strlen($bytes)]]),
            'https://api.telegram.org/file/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);
        $report = ['kind' => $kind, 'summary' => 'Visible screenshot details.', 'payment_status' => 'completed', 'amount' => '30000', 'currency' => 'MMK',
            'provider' => 'Fixture Pay', 'recipient_name' => 'Fixture merchant', 'recipient_account' => '091234', 'transaction_reference' => '001234', 'paid_at' => '2026-10-27T09:00:00+06:30'];
        TelegramImageReader::fake([$report]);

        return $report;
    }

    public function test_unknown_error_does_not_claim_report_delivery_without_admin_group(): void
    {
        $this->imageFixture(kind: 'error_unknown');
        DB::table('telegram_support_settings')->update(['admin_group_chat_id' => null]);
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertStringContainsString('No admin group report could be queued', Crypt::decryptString(DB::table('customer_service_messages')->value('reply')));
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
    }

    public function test_text_application_defect_routes_once_without_image_or_receipt_switches(): void
    {
        DB::table('telegram_support_settings')->where('id', 1)->update(['images_enabled' => false, 'receipt_notices_enabled' => false,
            'admin_group_chat_id' => -100777, 'admin_group_thread_id' => 77]);
        DB::table('telegram_support_groups')->insertOrIgnore(['bot_id' => 123456, 'chat_id' => -100777, 'title' => 'Private admin group', 'is_member' => true]);
        $agent = new CustomerServiceAgent;
        $this->app->bind(CustomerServiceAgent::class, fn () => $agent);
        CustomerServiceAgent::fake(function () use ($agent) {
            $tool = new CustomerServiceErrorReport($agent);
            $result = json_decode($tool->handle(new ToolRequest(['summary' => 'Mobile chat: large gap between keyboard and message input.', 'chat_id' => -100999])), true);
            $this->assertSame('prepared', $result['status']);

            return 'Prepared for review.';
        });
        $this->receive($this->update('Chat input leaves a gap above the keyboard.'))->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertStringContainsString('queued for the configured admin', Crypt::decryptString(DB::table('customer_service_messages')->value('reply')));
        app(ReceiptNotices::class)->process($this->id());
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('sent', DB::table('customer_service_receipt_notices')->value('status'));
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === -100777);
        $this->assertCount(1, $sent);
        $this->assertSame(77, $sent->first()[0]['message_thread_id']);
        $this->assertStringContainsString('large gap', $sent->first()[0]['text']);
        $this->assertStringContainsString('MKPOS ERROR', $sent->first()[0]['text']);
    }

    public function test_text_application_report_without_admin_destination_does_not_claim_success(): void
    {
        $agent = new CustomerServiceAgent;
        $this->app->bind(CustomerServiceAgent::class, fn () => $agent);
        CustomerServiceAgent::fake(function () use ($agent) {
            (new CustomerServiceErrorReport($agent))->handle(new ToolRequest(['summary' => 'Cancel button hover background is misplaced.']));

            return 'Prepared for review.';
        });
        $this->receive($this->update('Cancel button hover is broken.'))->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
        $this->assertStringContainsString('No admin group report could be queued', Crypt::decryptString(DB::table('customer_service_messages')->value('reply')));
    }

    public function test_development_defects_are_reported_without_requiring_a_correction(): void
    {
        $this->assertStringContainsString('Do not diagnose code', (new CustomerServiceAgent)->instructions());
        $this->assertStringContainsString('even if you recognize the symptom', (new TelegramImageReader)->instructions());
        $this->assertStringContainsString('An attempted correction is not a prerequisite', \App\Ai\Skills\ErrorSupport::instructions());
        $this->assertStringContainsString('without a separate contact question', implode(' ', \App\Ai\Skills\ErrorSupport::guide()['steps']));
    }

    public function test_unknown_error_routes_once_to_admin_even_when_receipt_notices_are_off(): void
    {
        $this->imageFixture(notices: false, kind: 'error_unknown');
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertStringContainsString('queued for the configured admin', Crypt::decryptString(DB::table('customer_service_messages')->value('reply')));
        app(ReceiptNotices::class)->process($this->id());
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('sent', DB::table('customer_service_receipt_notices')->value('status'));
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('MKPOS ERROR', $sent->first()[0]['caption']);
        $this->assertSame(-100777, $sent->first()[0]['chat_id']);
    }

    public function test_unrelated_image_and_known_error_never_create_admin_notices(): void
    {
        foreach (['unrelated', 'error_known'] as $index => $kind) {
            $this->imageFixture(kind: $kind);
            $this->receive($this->photoUpdate(100 + $index))->assertOk();
            $id = DB::table('customer_service_messages')->where('update_id', 100 + $index)->value('id');
            app(Runner::class)->process($id);
            $reply = Crypt::decryptString(DB::table('customer_service_messages')->where('id', $id)->value('reply'));
            $this->assertSame($kind === 'unrelated' ? 'This image is not related to the MKPOS system.' : 'Visible screenshot details.', $reply);
        }
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
    }

    public function test_receipt_checks_current_active_methods_and_accepts_matching_name_and_masked_prefix(): void
    {
        $policy = app(AiImagePolicy::class);
        $report = $this->imageFixture();
        DB::table('payment_methods')->delete();
        $report['recipient_account'] = '******1234';
        $this->assertSame('no_active_methods', $policy->paymentMethod($report)['status']);
        $id = DB::table('payment_methods')->insertGetId(['bank' => 'Fixture Pay', 'account_name' => 'Fixture merchant', 'account_no' => '091234']);
        $this->assertSame('matches_active_recipient', $policy->paymentMethod($report)['status']);
        $report['recipient_account'] = '091234';
        $this->assertSame('matches_active_recipient', $policy->paymentMethod($report)['status']);
        $this->assertFalse($policy->paymentMethod($report)['funds_verified']);
        DB::table('payment_methods')->where('id', $id)->update(['is_active' => false]);
        $this->assertSame('no_active_methods', $policy->paymentMethod($report)['status']);
        DB::table('payment_methods')->insert(['bank' => 'Other Pay', 'account_name' => 'Fixture merchant', 'account_no' => '091234']);
        $this->assertSame('does_not_match_active_recipient', $policy->paymentMethod($report)['status']);
    }

    public function test_nonmatching_inactive_missing_and_unreadable_suffix_recipients_never_queue_admin_receipts(): void
    {
        config(['telegram_support.per_chat_minute_limit' => 20]);
        foreach (['wrong_account', 'wrong_name', 'wrong_provider', 'inactive', 'missing', 'masked_suffix'] as $index => $case) {
            $report = $this->imageFixture();
            if ($case === 'wrong_account') {
                $report['recipient_account'] = '099999';
            }
            if ($case === 'wrong_name') {
                $report['recipient_name'] = 'Another recipient';
            }
            if ($case === 'wrong_provider') {
                $report['provider'] = 'Other Pay';
            }
            if ($case === 'inactive') {
                DB::table('payment_methods')->update(['is_active' => false]);
            }
            if ($case === 'missing') {
                DB::table('payment_methods')->delete();
                $report['recipient_account'] = '';
            }
            if ($case === 'masked_suffix') {
                $report['recipient_account'] = '******12**';
            }
            TelegramImageReader::fake([$report]);
            $this->receive($this->photoUpdate(500 + $index))->assertOk();
            $message = DB::table('customer_service_messages')->where('update_id', 500 + $index)->first();
            app(Runner::class)->process($message->id);
            $this->assertSame(0, DB::table('customer_service_receipt_notices')->count(), $case);
            $reply = Crypt::decryptString(DB::table('customer_service_messages')->where('id', $message->id)->value('reply'));
            $this->assertStringContainsString('not forwarded to the admin group', $reply, $case);
            Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto') || str_ends_with($r->url(), '/sendDocument'));
            DB::table('payment_methods')->delete();
        }
    }

    public function test_masked_prefix_with_matching_name_and_suffix_is_forwarded_with_review_checks(): void
    {
        $report = $this->imageFixture();
        $report['recipient_account'] = '******1234';
        TelegramImageReader::fake([$report]);
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame(1, DB::table('customer_service_receipt_notices')->count());
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('sent', DB::table('customer_service_receipt_notices')->value('status'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r['caption'], 'Receipt check: Needs review')
            && str_contains($r['caption'], 'recipient account: Match'));
    }

    public function test_used_provider_and_transaction_pair_is_rejected_and_never_forwarded(): void
    {
        $report = $this->imageFixture();
        Schema::create('subscription_requests', function (Blueprint $t) {
            $t->id(); $t->string('payment_bank'); $t->string('transaction_id'); $t->string('payment_transaction_key')->nullable();
        });
        DB::table('subscription_requests')->insert(['payment_bank' => $report['provider'], 'transaction_id' => $report['transaction_reference']]);
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $reply = Crypt::decryptString(DB::table('customer_service_messages')->where('id', $this->id())->value('reply'));
        $this->assertStringContainsString('Rejected:', $reply);
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
    }

    public function test_delivery_rechecks_current_methods_and_ignores_a_forged_cached_match(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame(1, DB::table('customer_service_receipt_notices')->count());
        DB::table('payment_methods')->update(['is_active' => false]);
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('cancelled', DB::table('customer_service_receipt_notices')->value('status'));
        $this->assertSame('recipient_not_confirmed', DB::table('customer_service_receipt_notices')->value('error_code'));
        DB::table('payment_methods')->update(['is_active' => true]);
        $row = DB::table('customer_service_messages')->first();
        $report = json_decode(Crypt::decryptString($row->image_report_encrypted), true);
        $report['recipient_account'] = '099999';
        $report['payment_method_check']['status'] = 'matches_active_recipient';
        DB::table('customer_service_messages')->where('id', $row->id)->update(['image_report_encrypted' => Crypt::encryptString(json_encode($report))]);
        DB::table('customer_service_receipt_notices')->update(['status' => 'pending']);
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('cancelled', DB::table('customer_service_receipt_notices')->value('status'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto') || str_ends_with($r->url(), '/sendDocument'));
    }

    public function test_myanmar_receipt_reply_localizes_server_messages_and_keeps_forwarding_blocked(): void
    {
        $report = $this->imageFixture();
        $report['recipient_account'] = '099999';
        TelegramImageReader::fake([$report]);
        $update = $this->photoUpdate();
        $update['message']['from']['language_code'] = 'my';
        $this->receive($update)->assertOk();
        app(Runner::class)->process($this->id());
        $reply = Crypt::decryptString(DB::table('customer_service_messages')->value('reply'));
        $this->assertStringContainsString(AiLocale::text('not_forwarded', 'my'), $reply);
        $this->assertStringContainsString(AiLocale::text('does_not_match_active_recipient', 'my'), $reply);
        $this->assertStringContainsString('001234', $reply);
        $this->assertStringNotContainsString('not forwarded', $reply);
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
    }

    public function test_receipt_image_is_read_only_in_worker_and_admin_gets_one_manual_review_notice(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        $this->receive($this->photoUpdate())->assertOk();
        Http::assertNothingSent();
        $this->assertStringNotContainsString('fixture_image_file_id', DB::table('jobs')->value('payload'));
        $this->assertNotSame('fixture_image_file_id', DB::table('customer_service_messages')->value('image_file_id_encrypted'));
        app(Runner::class)->process($this->id());
        $row = DB::table('customer_service_messages')->first();
        $this->assertSame('receipt', json_decode(Crypt::decryptString($row->image_report_encrypted), true)['kind']);
        $this->assertStringContainsString('not verified', Crypt::decryptString($row->reply));
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        $notices = app(ReceiptNotices::class);
        $notices->process($this->id());
        $notices->process($this->id());
        $this->assertSame('sent', DB::table('customer_service_receipt_notices')->value('status'));
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        $this->assertCount(1, $sent);
        $request = $sent->first()[0];
        $this->assertSame(-100777, $request['chat_id']);
        $this->assertSame(77, $request['message_thread_id']);
        $this->assertSame('fixture_image_file_id', $request['photo']);
        $this->assertStringContainsString('NOT verified', $request['caption']);
        $this->assertTrue($request['protect_content']);
        TelegramImageReader::assertPromptedTimes(1);
        TelegramImageReader::assertPrompted(fn (AgentPrompt $p) => count($p->attachments) === 1);
        CustomerServiceAgent::assertNeverPrompted();
    }

    public function test_myanmar_admin_receipt_notice_preserves_account_reference_and_review_warning(): void
    {
        $this->imageFixture();
        $update = $this->photoUpdate();
        $update['message']['from']['language_code'] = 'my';
        $this->receive($update)->assertOk();
        app(Runner::class)->process($this->id());
        app(ReceiptNotices::class)->process($this->id());
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $sent);
        $caption = $sent->first()[0]['caption'];
        $this->assertStringContainsString(AiLocale::text('recipient_account', 'my'), $caption);
        $this->assertStringContainsString(AiLocale::text('active_recipient_notice', 'my'), $caption);
        $this->assertStringContainsString('001234', $caption);
        $this->assertStringContainsString(AiLocale::text('review_id', 'my'), $caption);
        $this->assertSame('sent', DB::table('customer_service_receipt_notices')->value('status'));
    }

    public function test_general_image_does_not_notify_admin_and_image_documents_are_supported(): void
    {
        $this->imageFixture(kind: 'general');
        $this->receive($this->photoUpdate(document: true))->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame('document', DB::table('customer_service_messages')->value('image_source'));
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto') || str_ends_with($r->url(), '/sendDocument'));
    }

    public function test_disabled_oversized_or_non_image_uploads_never_download_or_call_ai(): void
    {
        $this->receive($this->photoUpdate())->assertOk();
        $this->assertFalse((bool) DB::table('customer_service_messages')->value('uses_ai'));
        DB::table('telegram_support_settings')->where('id', 1)->update(['images_enabled' => true]);
        $large = $this->photoUpdate(2);
        $large['message']['photo'][0]['file_size'] = 6000000;
        $this->receive($large)->assertOk();
        $pdf = $this->photoUpdate(3, true);
        $pdf['message']['document']['mime_type'] = 'application/pdf';
        $this->receive($pdf)->assertOk();
        $this->assertSame(0, DB::table('customer_service_messages')->where('uses_ai', true)->count());
        $this->assertSame(0, DB::table('customer_service_budget_days')->value('requests'));
        Http::assertNothingSent();
    }

    public function test_image_download_rejects_unsafe_paths_before_downloading_or_calling_ai(): void
    {
        $this->imageFixture();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => '../../.env']]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame('generation_unavailable', DB::table('customer_service_messages')->value('error_code'));
        $this->assertSame(0, DB::table('ai_balance_entries')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/file/bot'));
        TelegramImageReader::assertNeverPrompted();
    }

    public function test_receipt_notice_retries_only_telegram_429_and_never_repeats_the_model(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::sequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 2]], 429)
            ->push(['ok' => true, 'result' => ['message_id' => 502]])]);
        $notices = app(ReceiptNotices::class);
        $this->assertSame(2, $notices->process($this->id()));
        $this->travel(3)->seconds();
        $notices->process($this->id());
        $this->assertSame('sent', DB::table('customer_service_receipt_notices')->value('status'));
        TelegramImageReader::assertPromptedTimes(1);
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
    }

    public function test_receipt_retry_is_cancelled_when_recipient_changes_after_rate_limit(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 2]], 429)]);
        $this->assertSame(2, app(ReceiptNotices::class)->process($this->id()));
        DB::table('payment_methods')->update(['account_no' => '099999']);
        $this->travel(3)->seconds();
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('cancelled', DB::table('customer_service_receipt_notices')->value('status'));
        Http::assertSentCount(1);
        TelegramImageReader::assertPromptedTimes(1);
    }

    public function test_ambiguous_admin_notice_is_terminal_and_changed_destination_cancels_pending_notice(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake(['https://api.telegram.org/*/sendPhoto' => function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('Fixture timeout');
        }]);
        $notices = app(ReceiptNotices::class);
        $notices->process($this->id());
        $notices->process($this->id());
        $this->assertSame(1, $attempts);
        $this->assertSame('delivery_unknown', DB::table('customer_service_receipt_notices')->value('status'));
        DB::table('customer_service_receipt_notices')->update(['status' => 'pending']);
        DB::table('telegram_support_settings')->where('id', 1)->update(['admin_group_chat_id' => -100999]);
        $notices->process($this->id());
        $this->assertSame('cancelled', DB::table('customer_service_receipt_notices')->value('status'));
        $this->assertSame(1, $attempts);
    }

    public function test_receipt_reset_erases_evidence_and_prevents_admin_forwarding(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->receive($this->update('/reset', 2))->assertOk();
        $row = DB::table('customer_service_messages')->where('update_id', 1)->first();
        $this->assertNull($row->image_file_id_encrypted);
        $this->assertNull($row->image_report_encrypted);
        app(ReceiptNotices::class)->process($this->id());
        $this->assertSame('cancelled', DB::table('customer_service_receipt_notices')->value('status'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
    }

    public function test_receipt_notice_settings_require_a_real_group_for_the_current_bot(): void
    {
        $this->imageFixture(notices: false);
        try {
            $this->officeSettings(['images_enabled' => true, 'receipt_notices_enabled' => true, 'admin_group_chat_id' => -100999]);
            $this->fail('Unknown admin group must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('admin_group_chat_id', $error->errors());
        }
        $this->officeSettings(['images_enabled' => true, 'receipt_notices_enabled' => true, 'admin_group_chat_id' => -100777]);
        $this->assertTrue(app(Settings::class)->get()['receipt_notices_enabled']);
        $this->officeSettings(['bot_token' => '654321:replacement_token_12345678901234567890']);
        $this->assertFalse(app(Settings::class)->get()['receipt_notices_enabled']);
        $this->assertNull(app(Settings::class)->get()['admin_group_chat_id']);
    }

    public function test_group_photo_caption_requires_approval_and_keeps_the_source_topic(): void
    {
        $this->imageFixture();
        $value = $this->photoUpdate(document: true);
        $value['message']['chat'] = ['id' => -1009001, 'type' => 'supergroup', 'title' => 'Support group'];
        $value['message']['caption'] = '@fixture_support_bot Read receipt';
        $value['message']['caption_entities'] = [['type' => 'mention', 'offset' => 0, 'length' => 20]];
        $value['message']['message_thread_id'] = 88;
        $this->receive($value)->assertOk();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->approveGroups();
        $value['update_id'] = 2;
        $value['message']['message_id'] = 2;
        $this->receive($value)->assertOk();
        app(Runner::class)->process($this->id(2));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === -1009001 && $r['message_thread_id'] === 88);
        app(ReceiptNotices::class)->process($this->id(2));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendDocument') && $r['chat_id'] === -100777 && $r['message_thread_id'] === 77);
    }

    public function test_invalid_extraction_is_not_forwarded_but_incurred_usage_is_recorded(): void
    {
        $report = $this->imageFixture();
        $report['kind'] = 'approve_payment';
        TelegramImageReader::fake([$report]);
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame('generation_unavailable', DB::table('customer_service_messages')->value('error_code'));
        $this->assertNotNull(DB::table('customer_service_messages')->value('charged_at'));
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_service_receipt_notices')->count());
    }

    public function test_expired_image_references_are_pruned_and_pending_notices_are_cancelled(): void
    {
        $this->imageFixture();
        $this->receive($this->photoUpdate())->assertOk();
        app(Runner::class)->process($this->id());
        DB::table('customer_service_messages')->update(['created_at' => now('UTC')->subDays(31)]);
        $this->artisan('customer-service:prune')->assertSuccessful();
        $this->assertNull(DB::table('customer_service_messages')->value('image_file_id_encrypted'));
        $this->assertNull(DB::table('customer_service_messages')->value('image_report_encrypted'));
        $this->assertSame('cancelled', DB::table('customer_service_receipt_notices')->value('status'));
    }

    private function typingFixture(): TypingIndicator
    {
        $typing = new class extends TypingIndicator
        {
            public float $time = 100;

            protected function clock(): float
            {
                return $this->time;
            }
        };
        $this->app->instance(TypingIndicator::class, $typing);

        return $typing;
    }

    public function test_typing_refreshes_during_model_progress_and_never_leaks_into_the_next_job(): void
    {
        $typing = $this->typingFixture();
        $this->receive($this->update())->assertOk();
        Http::assertNothingSent();
        CustomerServiceAgent::fake(function () use ($typing) {
            $this->assertTrue($typing->active());
            $typing->time += 3;
            $typing->tick();
            Http::assertSentCount(1);
            $previousCalls = 0;
            $middleware = (new RefreshTelegramTyping)(function ($request, $options) use ($typing) {
                $typing->time += 1;
                $this->assertSame(0, $options['progress'](0, 0, 0, 0));
                $typing->time += 4;
                $this->assertSame(0, $options['progress'](100, 50, 0, 0));

                return Create::promiseFor(new Response(200));
            });
            $middleware(new Request('POST', 'https://api.deepseek.com/v1/chat/completions'),
                ['progress' => function () use (&$previousCalls) {
                    $previousCalls++;

                    return 0;
                }])->wait();
            $this->assertSame(2, $previousCalls);

            return 'Answer';
        });
        app(Runner::class)->process($this->id());
        $this->assertFalse($typing->active());
        $typing->time += 10;
        $typing->tick();
        Http::assertSentCount(4); // Three ephemeral actions and one real answer.
        CustomerServiceAgent::fake(['Another answer']);
        $this->receive($this->update('Other chat', 2, 9002))->assertOk();
        app(Runner::class)->process($this->id(2));
        $actions = Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendChatAction'))->map(fn ($pair) => $pair[0]['chat_id'])->values()->all();
        $this->assertSame([9001, 9001, 9001, 9002], $actions);
        $this->assertSame(2, DB::table('ai_balance_entries')->count());
    }

    public function test_typing_rate_limit_is_respected_without_repeating_or_blocking_the_answer(): void
    {
        $typing = $this->typingFixture();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendChatAction' => Http::sequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 8]], 429)
                ->push(['ok' => true, 'result' => true]),
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);
        $this->receive($this->update())->assertOk();
        CustomerServiceAgent::fake(function () use ($typing) {
            $typing->time += 4;
            $typing->tick();
            Http::assertSentCount(1);
            $typing->time += 4;
            $typing->tick();
            Http::assertSentCount(2);

            return 'Answer despite typing rate limit';
        });
        app(Runner::class)->process($this->id());
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        Http::assertSentCount(3);
        CustomerServiceAgent::assertPromptedTimes(1);
    }

    public function test_typing_transport_failure_cannot_fail_or_retry_the_model(): void
    {
        $typing = $this->typingFixture();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendChatAction' => fn () => throw new \RuntimeException('Fixture transport failure; must stay private'),
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);
        $this->receive($this->update())->assertOk();
        app(Runner::class)->process($this->id());
        $this->assertSame('delivered', DB::table('customer_service_messages')->value('status'));
        $this->assertFalse($typing->active());
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        CustomerServiceAgent::assertPromptedTimes(1);
    }

    public function test_typing_stops_when_group_is_disabled_and_uses_the_correct_topic(): void
    {
        $typing = $this->typingFixture();
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $this->receive($this->groupUpdate(2, thread: 77))->assertOk();
        CustomerServiceAgent::fake(function () use ($typing) {
            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendChatAction') && $r['chat_id'] === -1009001 && $r['message_thread_id'] === 77);
            DB::table('telegram_support_groups')->update(['status' => 'disabled']);
            $typing->time += 4;
            $typing->tick();
            $this->assertFalse($typing->active());
            $typing->time += 4;
            $typing->tick();
            Http::assertSentCount(1);

            return 'Suppressed answer';
        });
        app(Runner::class)->process($this->id(2));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
    }

    private function groupUpdate(int $update = 1, int $chat = -1009001, int $sender = 9001, int $thread = 0): array
    {
        $value = $this->update('@fixture_support_bot What is MKPOS?', $update, $sender);
        $value['message']['chat'] = ['type' => 'supergroup', 'id' => $chat, 'title' => 'Support '.$chat];
        $value['message']['entities'] = [['type' => 'mention', 'offset' => 0, 'length' => 20]];
        if ($thread) {
            $value['message']['message_thread_id'] = $thread;
        }

        return $value;
    }

    private function approveGroups(): void
    {
        DB::table('telegram_support_settings')->where('id', 1)->update(['groups_enabled' => true, 'bot_username' => 'fixture_support_bot']);
        DB::table('telegram_support_groups')->update(['status' => 'approved']);
    }

    public function test_multiple_groups_are_discovered_pending_and_only_approved_addressed_messages_are_queued(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->receive($this->groupUpdate(2, -1009002))->assertOk();
        $this->assertSame(2, DB::table('telegram_support_groups')->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->approveGroups();
        $this->receive($this->groupUpdate(3))->assertOk();
        $this->receive($this->groupUpdate(4, -1009002))->assertOk();
        $ordinary = $this->groupUpdate(5);
        unset($ordinary['message']['entities']);
        $this->receive($ordinary)->assertOk();
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(2, DB::table('customer_service_conversations')->count());
        $this->assertSame('What is MKPOS?', Crypt::decryptString(DB::table('customer_service_messages')->where('update_id', 3)->value('input')));
        Http::assertNothingSent();
        CustomerServiceAgent::assertNeverPrompted();
    }

    public function test_group_history_is_isolated_by_group_sender_and_topic_and_delivery_preserves_topic(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $this->receive($this->groupUpdate(2, thread: 77))->assertOk();
        app(Runner::class)->process($this->id(2));
        Http::assertSent(fn ($r) => $r['chat_id'] === -1009001 && $r['message_thread_id'] === 77);
        foreach ([$this->groupUpdate(3, sender: 9002, thread: 77), $this->groupUpdate(4, thread: 88), $this->update('Private question', 5)] as $update) {
            $this->receive($update)->assertOk();
        }
        CustomerServiceAgent::fake(function (AgentPrompt $prompt) {
            $this->assertSame([], $prompt->agent->history);

            return 'Isolated answer';
        });
        foreach ([3, 4, 5] as $id) {
            app(Runner::class)->process($this->id($id));
        }
        $this->assertSame(4, DB::table('customer_service_conversations')->count());
    }

    public function test_group_limits_apply_across_topics_and_users_and_private_chats_are_unaffected(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        DB::table('telegram_support_groups')->update(['daily_limit' => 2, 'per_user_daily_limit' => 1]);
        foreach ([$this->groupUpdate(2), $this->groupUpdate(3, thread: 77), $this->groupUpdate(4, sender: 9002), $this->groupUpdate(5, sender: 9003), $this->update('Private', 6)] as $update) {
            $this->receive($update)->assertOk();
        }
        $this->assertSame(3, DB::table('customer_service_messages')->where('uses_ai', true)->count());
        $this->assertFalse((bool) DB::table('customer_service_messages')->where('update_id', 3)->value('uses_ai'));
        $this->assertFalse((bool) DB::table('customer_service_messages')->where('update_id', 5)->value('uses_ai'));
    }

    public function test_group_reply_trigger_utf16_mentions_and_membership_removal_are_checked(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $reply = $this->groupUpdate(2);
        unset($reply['message']['entities']);
        $reply['message']['text'] = 'Tell me more';
        $reply['message']['reply_to_message'] = ['message_id' => 501, 'from' => ['id' => 123456, 'is_bot' => true]];
        $this->receive($reply)->assertOk();
        $mention = $this->groupUpdate(3);
        $mention['message']['text'] = '😀 @fixture_support_bot Question';
        $mention['message']['entities'][0]['offset'] = 3;
        $this->receive($mention)->assertOk();
        $this->assertSame(2, DB::table('jobs')->count());
        $this->receive(['update_id' => 4, 'my_chat_member' => ['chat' => $reply['message']['chat'],
            'new_chat_member' => ['user' => ['id' => 123456], 'status' => 'left']]])->assertOk();
        app(Runner::class)->process($this->id(2));
        $this->assertSame('cancelled', DB::table('customer_service_messages')->where('update_id', 2)->value('status'));
        Http::assertNothingSent();
        CustomerServiceAgent::assertNeverPrompted();
    }

    public function test_office_group_management_is_super_admin_only_scoped_and_versioned(): void
    {
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
        $path = '/api/office/telegram-support/groups';
        $this->receive($this->groupUpdate())->assertOk();
        $this->getJson($path)->assertUnauthorized();
        $admin = new PlatformAdmin(['name' => 'Fixture', 'email' => 'fixture@example.test', 'is_active' => true, 'role' => 'admin']);
        $admin->id = 1;
        $this->actingAs($admin, 'office');
        $this->getJson($path)->assertForbidden();
        $this->postJson($path, ['chat_id' => -200, 'title' => 'Other'])->assertForbidden();
        $this->putJson($path.'/1', [])->assertForbidden();
        $admin->role = 'super_admin';
        $this->getJson($path)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('groups.0.status', 'pending');
        $body = ['version' => 1, 'status' => 'approved', 'reply_mentions' => true, 'reply_to_bot' => false, 'daily_limit' => 50, 'per_user_daily_limit' => 5];
        $this->putJson($path.'/1', $body)->assertOk();
        $this->putJson($path.'/1', $body)->assertConflict();
        $this->putJson($path.'/1', array_replace($body, ['version' => 2, 'reply_mentions' => false]))->assertUnprocessable();
        $this->postJson($path, ['chat_id' => 100, 'title' => 'Invalid'])->assertUnprocessable();
        $this->postJson($path, ['chat_id' => -200, 'title' => 'Second group'])->assertOk();
        $this->postJson($path, ['chat_id' => -200, 'title' => 'Second group'])->assertConflict();
        DB::table('telegram_support_groups')->insert(['bot_id' => 999, 'chat_id' => -300, 'title' => 'Other bot']);
        $this->getJson($path.'?search=Second&per_page=10')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('groups.0.chat_id', '-200');
        $this->getJson($path.'?status=approved')->assertOk()->assertJsonPath('total', 1);
        $this->putJson($path.'/3', $body)->assertNotFound();
    }

    public function test_group_disable_during_generation_suppresses_delivery_but_records_incurred_cost(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $this->receive($this->groupUpdate(2))->assertOk();
        CustomerServiceAgent::fake(function () {
            DB::table('telegram_support_groups')->update(['status' => 'disabled']);

            return 'Already generated';
        });
        app(Runner::class)->process($this->id(2));
        $this->assertSame('cancelled', DB::table('customer_service_messages')->value('status'));
        $this->assertSame(1, DB::table('ai_balance_entries')->count());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
    }

    public function test_global_group_disable_cancels_pending_group_work_but_not_private_work(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $this->receive($this->groupUpdate(2))->assertOk();
        $this->receive($this->update('Private', 3))->assertOk();
        $this->officeSettings(['groups_enabled' => false]);
        $this->officeSettings(['groups_enabled' => true]);
        app(Runner::class)->process($this->id(2));
        $this->assertSame('cancelled', DB::table('customer_service_messages')->where('update_id', 2)->value('status'));
        $this->assertSame('queued', DB::table('customer_service_messages')->where('update_id', 3)->value('status'));
        CustomerServiceAgent::assertNeverPrompted();
    }

    public function test_group_reset_command_clears_only_the_requesting_participant(): void
    {
        $this->receive($this->groupUpdate())->assertOk();
        $this->approveGroups();
        $this->receive($this->groupUpdate(2))->assertOk();
        $this->receive($this->groupUpdate(3, sender: 9002))->assertOk();
        $reset = $this->groupUpdate(4);
        $reset['message']['text'] = '/reset@fixture_support_bot';
        $reset['message']['entities'] = [['type' => 'bot_command', 'offset' => 0, 'length' => 26]];
        $this->receive($reset)->assertOk();
        $this->assertSame('cancelled', DB::table('customer_service_messages')->where('update_id', 2)->value('status'));
        $this->assertNull(DB::table('customer_service_messages')->where('update_id', 2)->value('input'));
        $this->assertSame('queued', DB::table('customer_service_messages')->where('update_id', 3)->value('status'));
        $this->assertNotNull(DB::table('customer_service_messages')->where('update_id', 3)->value('input'));
        $this->assertFalse((bool) DB::table('customer_service_messages')->where('update_id', 4)->value('uses_ai'));
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
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendChatAction') && $r['action'] === 'typing' && $r['chat_id'] === 9001);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === 9001 && $request['text'] === 'MKPOS helps you manage your shop.' && ! isset($request['parse_mode']));
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
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === 9002 && $request['text'] === 'Public package explanation');
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
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage'));
    }

    public function test_telegram_rate_limit_retries_only_the_saved_answer_without_another_model_call(): void
    {
        $this->receive($this->update())->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendChatAction' => Http::response(['ok' => true, 'result' => true]),
            'https://api.telegram.org/*/sendMessage' => Http::sequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 2]], 429)
                ->push(['ok' => true, 'result' => ['message_id' => 502]]),
        ]);
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

    public function test_kaung_kaung_identity_and_public_support_workflows_have_no_private_tools_or_writes(): void
    {
        $agent = new CustomerServiceAgent(language: 'my');
        $this->assertStringContainsString('Your name is Kaung Kaung', $agent->instructions());
        $this->assertStringContainsString('not a human administrator', $agent->instructions());
        $this->assertStringContainsString('clearing pending transactions', $agent->instructions());
        $tool = new CustomerServiceSkills($agent);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $list = json_decode($tool->handle(new ToolRequest(['topic' => 'all'])), true);
        $this->assertSame([], $queries);
        $this->assertSame('Kaung Kaung', $list['name']);
        $this->assertCount(13, $list['skills']);
        $this->assertArrayNotHasKey('steps', $list['skills']['plans']);
        foreach ($list['skills'] as $skill) {
            foreach ($skill['tools'] as $name) {
                $this->assertArrayHasKey($name, CustomerServiceKnowledge::TOOLS);
            }
        }
        $guide = json_decode($tool->handle(new ToolRequest(['topic' => 'subscription_payments'])), true);
        $this->assertNotEmpty($guide['skills']['subscription_payments']['steps']);
        $this->assertStringContainsString('do not verify bank funds', implode(' ', $guide['skills']['subscription_payments']['steps']));
        $this->assertContains('get_customer_service_skills', array_map(fn ($item) => $item->name(), iterator_to_array($agent->tools())));
        // Knowing Kaung Kaung's role must not give a generic image helper his identity.
        $this->assertStringNotContainsString('Your public name is Kaung Kaung', (new TelegramImageReader)->instructions());
        $this->assertStringContainsString('Your public name is Kaung Kaung', (new TelegramImageReader(identity: 'Kaung Kaung'))->instructions());
    }

    public function test_start_and_help_use_kaung_kaung_with_images_on_or_off_without_ai_usage(): void
    {
        $this->imageFixture();
        foreach ([false, true] as $index => $images) {
            DB::table('telegram_support_settings')->update(['images_enabled' => $images]);
            $this->receive($this->update($images ? '/help' : '/start', 301 + $index))->assertOk();
            $message = DB::table('customer_service_messages')->where('update_id', 301 + $index)->first();
            $this->assertFalse((bool) $message->uses_ai);
            $this->assertStringContainsString('Kaung Kaung', Crypt::decryptString($message->reply));
            if ($images) {
                $this->assertStringContainsString('error screenshots', Crypt::decryptString($message->reply));
            }
        }
        $this->assertSame(0, DB::table('ai_balance_entries')->count());
        Http::assertNothingSent();
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
            && $request['allowed_updates'] === ['message', 'my_chat_member'] && $request['max_connections'] === 1 && $request['drop_pending_updates'] === false);
        $this->assertSame('fixture_support_bot', app(Settings::class)->get()['bot_username']);
        $this->assertNotNull(app(Settings::class)->get()['last_registered_at']);
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
        Http::assertSentCount(2);
        CustomerServiceAgent::assertPromptedTimes(1);
    }
}
