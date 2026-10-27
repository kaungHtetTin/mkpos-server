<?php

namespace Tests\Feature;

use App\Ai\Agents\MkposAssistant;
use App\Ai\Agents\TelegramImageReader;
use App\Ai\Personas\WyneWyne;
use App\Ai\Tools\ErrorSupportTool;
use App\Ai\Tools\WyneWyneSkills;
use App\Jobs\WyneWyneReply;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\AiBalanceBook;
use App\Services\AiChatImages;
use App\Services\AiProviderCredentials;
use App\Services\AiUsageReport;
use App\Services\DeepSeekPricing;
use App\Services\ErrorReports;
use App\Services\SubscriptionService;
use App\Services\WyneWyneRunner;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.ai_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('ai_test');
        config()->set('cache.default', 'array');
        config()->set('ai.providers.deepseek.key', 'test-key-never-return');
        Http::preventStrayRequests();
        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('status')->default('active');
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('business_id');
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('businesses')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
        $this->owner = User::create(['business_id' => 1, 'name' => 'Owner', 'email' => 'owner@test.example', 'password' => 'test-password', 'role' => 'owner', 'is_active' => true]);
        (require database_path('migrations/2026_10_25_000001_create_ai_assistant_tables.php'))->up();
        (require database_path('migrations/2026_10_26_000002_protect_ai_conversation_history.php'))->up();
        (require database_path('migrations/2026_10_26_000004_queue_wyne_wyne_replies.php'))->up();
        (require database_path('migrations/2026_10_25_000011_use_weekly_ai_request_limit.php'))->up();
        (require database_path('migrations/2026_10_25_000012_add_encrypted_ai_api_key.php'))->up();
        (require database_path('migrations/2026_10_25_000013_add_ai_usd_to_mmk_rate.php'))->up();
        (require database_path('migrations/2026_10_25_000014_add_sales_ai_markup.php'))->up();
        (require database_path('migrations/2026_10_25_000002_create_ai_balance_book_tables.php'))->up();
        (require database_path('migrations/2026_10_25_000003_add_cached_input_tokens_to_ai_usage.php'))->up();
        (require database_path('migrations/2026_10_25_000007_create_error_reports.php'))->up();
        (require database_path('migrations/2026_10_25_000008_add_error_report_contact_requests.php'))->up();
        (require database_path('migrations/2026_10_27_000002_add_image_escalation_key_to_error_reports.php'))->up();
        app(AiBalanceBook::class)->topUp((object) ['id' => 999, 'name' => 'Test budget'], ['request_key' => (string) Str::uuid(), 'amount' => '10', 'description' => 'Test opening credit']);
        Cache::put('ai.deepseek.pricing.v2', ['source_url' => DeepSeekPricing::SOURCE, 'fetched_at' => now()->toIso8601String(), 'rates' => ['deepseek-flash' => ['input' => 0.3, 'output' => 1.2, 'cached_input' => 0.006]]], 3600);
        DB::table('ai_assistant_settings')->where('id', 1)->update(['enabled' => true]);
        $this->mock(SubscriptionService::class, fn ($mock) => $mock->shouldReceive('status')->andReturn(['is_valid' => true, 'can_mutate' => true, 'access_type' => 'paid', 'reason' => null]));
        $this->actingAs($this->owner, 'web');
    }

    public function test_wyne_skill_guide_is_read_only_and_lists_only_real_exposed_tools(): void
    {
        app(TenantContext::class)->set(1);
        config(['mkpos.enabled_modules' => ['reports', 'products', 'purchases', 'transactions', 'expenses', 'shop']]);
        $agent = new MkposAssistant($this->owner);
        $tool = new WyneWyneSkills($agent);
        $list = json_decode($tool->handle(new Request(['topic' => 'all'])), true);
        $this->assertCount(17, $list['skills']);
        $this->assertArrayHasKey('sales_analysis', $list['skills']);
        $this->assertArrayNotHasKey('steps', $list['skills']['sales_analysis']);
        $actual = collect($agent->tools())->map(fn ($item) => method_exists($item, 'name') ? $item->name() : class_basename($item))->all();
        foreach ($list['skills'] as $guide) {
            foreach ($guide['available_tools'] as $name) {
                $this->assertContains($name, $actual);
            }
        }
        $guide = json_decode($tool->handle(new Request(['topic' => 'inventory'])), true);
        $this->assertNotEmpty($guide['skills']['inventory']['steps']);
        $this->assertContains('get_inventory_report', $guide['skills']['inventory']['available_tools']);
        $this->assertStringContainsString('get_wyne_wyne_skills', $agent->instructions());
        $this->assertSame(0, DB::table('ai_messages')->count());
        $this->assertSame(0, DB::table('error_reports')->count());
        $this->assertSame(0, DB::table('ai_runs')->count());
    }

    public function test_skill_guides_recheck_demotion_modules_and_tenant_on_every_call(): void
    {
        app(TenantContext::class)->set(1);
        config(['mkpos.enabled_modules' => ['reports', 'products']]);
        $agent = new MkposAssistant($this->owner);
        $tool = new WyneWyneSkills($agent);
        $this->assertArrayHasKey('inventory', json_decode($tool->handle(new Request(['topic' => 'all'])), true)['skills']);
        config(['mkpos.enabled_modules' => ['reports']]);
        $this->assertSame('skill_unavailable', json_decode($tool->handle(new Request(['topic' => 'inventory'])), true)['error']);
        DB::table('users')->where('id', $this->owner->id)->update(['role' => 'staff']);
        $skills = json_decode($tool->handle(new Request(['topic' => 'all'])), true)['skills'];
        $this->assertSame(['getting_started', 'navigation', 'images_support', 'security_devices'], array_keys($skills));
        foreach ($skills as $guide) {
            $this->assertEmpty(array_diff($guide['available_tools'], ['open_app_screen']));
        }
        $this->assertSame('skill_unavailable', json_decode($tool->handle(new Request(['topic' => 'sales_analysis'])), true)['error']);
        app(TenantContext::class)->set(2);
        $this->assertSame('skill_unavailable', json_decode($tool->handle(new Request(['topic' => 'management'])), true)['error']);
    }

    private function conversation(): string
    {
        $id = (string) Str::uuid();
        $this->postJson('/api/ai-assistant/conversations', ['id' => $id])->assertOk();

        return $id;
    }

    private function backgroundQueue(): void
    {
        config(['queue.connections.wyne-wyne.driver' => 'database', 'queue.connections.wyne-wyne.connection' => 'ai_test']);
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function stagePendingReply(string $conversation): string
    {
        $message = DB::table('ai_messages')->insertGetId(['conversation_id' => $conversation, 'client_id' => (string) Str::uuid(),
            'role' => 'user', 'body' => 'Private report', 'status' => 'pending', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $id = (string) Str::uuid();
        DB::table('ai_runs')->insert(['id' => $id, 'user_id' => $this->owner->id, 'business_id' => 1, 'model' => 'deepseek-flash',
            'status' => 'pending', 'message_id' => $message, 'actor_role' => 'owner', 'language' => 'en', 'app' => 'business_web',
            'max_tokens' => 1600, 'pricing' => json_encode(app(AiBalanceBook::class)->pricing('deepseek-flash')),
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return $id;
    }

    private function processStagedReply(): void
    {
        app(WyneWyneRunner::class)->process(DB::table('ai_runs')->whereNotNull('message_id')->value('id'));
    }

    public function test_http_reply_completes_without_a_worker_and_duplicate_submission_does_not_repeat_usage(): void
    {
        $this->backgroundQueue();
        MkposAssistant::fake(['Direct HTTP fixture reply']);
        $conversation = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$conversation.'/messages';
        $data = ['client_id' => (string) Str::uuid(), 'body' => 'Help me'];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('pending', false)->assertJsonPath('status', 'completed')
            ->assertJsonCount(2, 'items')->assertJsonPath('items.1.body', 'Direct HTTP fixture reply');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('items.1.body', 'Direct HTTP fixture reply');
        $this->postJson($url, ['client_id' => $data['client_id'], 'body' => 'Different content'])->assertConflict();
        $this->getJson($url.'/'.$data['client_id'].'/delivery')->assertOk()->assertJsonPath('status', 'completed');
        app(WyneWyneRunner::class)->process(DB::table('ai_runs')->value('id'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('ai_runs')->count());
        $this->assertSame(1, DB::table('ai_messages')->where('role', 'assistant')->count());
        $this->assertSame(1, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        MkposAssistant::assertPromptedTimes(1);
    }

    public function test_runner_rechecks_role_and_active_account_before_using_private_history(): void
    {
        $this->backgroundQueue();
        MkposAssistant::fake(['Never publish']);
        $conversation = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$conversation.'/messages';
        $this->stagePendingReply($conversation);
        DB::table('users')->where('id', $this->owner->id)->update(['role' => 'staff']);
        $this->processStagedReply();
        $this->assertSame('failed', DB::table('ai_runs')->value('status'));
        $this->assertSame('access_changed', DB::table('ai_runs')->value('error_code'));
        $this->assertSame(0, DB::table('ai_messages')->where('role', 'assistant')->count());
        MkposAssistant::assertNeverPrompted();
    }

    public function test_runner_restores_tenant_context_and_never_uses_the_http_users_business(): void
    {
        $this->backgroundQueue();
        $conversation = $this->conversation();
        $this->stagePendingReply($conversation);
        $tenant = app(TenantContext::class);
        $tenant->set(2);
        MkposAssistant::fake(function () use ($tenant) {
            $this->assertSame(1, $tenant->id());

            return 'Tenant-scoped fixture';
        });
        $this->processStagedReply();
        $this->assertSame(2, $tenant->id());
        $this->assertSame('completed', DB::table('ai_runs')->value('status'));
    }

    public function test_runner_does_not_call_the_provider_after_account_deactivation(): void
    {
        $this->backgroundQueue();
        MkposAssistant::fake(['Never publish']);
        $conversation = $this->conversation();
        $this->stagePendingReply($conversation);
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
        $this->processStagedReply();
        $this->assertSame('access_changed', DB::table('ai_runs')->value('error_code'));
        $this->assertSame(0, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        MkposAssistant::assertNeverPrompted();
    }

    public function test_interrupted_provider_calls_are_failed_without_automatic_replay_and_historical_runs_are_ignored(): void
    {
        $this->backgroundQueue();
        MkposAssistant::fake(['Never replay']);
        $conversation = $this->conversation();
        $this->stagePendingReply($conversation);
        DB::table('ai_runs')->update(['status' => 'processing', 'started_at' => now('UTC')->subMinutes(11), 'updated_at' => now('UTC')->subMinutes(11)]);
        $this->artisan('wyne-wyne:recover')->assertSuccessful();
        $this->assertSame('http_interrupted', DB::table('ai_runs')->value('error_code'));
        $this->processStagedReply();
        MkposAssistant::assertNeverPrompted();
        DB::table('ai_runs')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'business_id' => 1,
            'model' => 'deepseek-flash', 'status' => 'pending', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->artisan('wyne-wyne:recover')->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_cleanup_never_queues_provider_calls_and_expires_only_abandoned_requests(): void
    {
        $this->backgroundQueue();
        MkposAssistant::fake(['Never replay']);
        $id = $this->stagePendingReply($this->conversation());
        $this->artisan('wyne-wyne:recover')->assertSuccessful();
        $this->assertSame('pending', DB::table('ai_runs')->value('status'));
        $this->travel(11)->minutes();
        $this->artisan('wyne-wyne:recover')->assertSuccessful();
        $this->assertSame('failed', DB::table('ai_runs')->value('status'));
        (new WyneWyneReply($id))->handle(app(WyneWyneRunner::class));
        $this->assertSame(0, DB::table('jobs')->count());
        MkposAssistant::assertNeverPrompted();
    }

    public function test_old_queued_jobs_are_retired_without_using_the_provider(): void
    {
        MkposAssistant::fake(['Never run in a worker']);
        $id = $this->stagePendingReply($this->conversation());
        (new WyneWyneReply($id))->handle(app(WyneWyneRunner::class));
        $this->assertSame('failed', DB::table('ai_runs')->value('status'));
        $this->assertSame('http_retry_required', DB::table('ai_runs')->value('error_code'));
        $this->assertSame('failed', DB::table('ai_messages')->value('status'));
        MkposAssistant::assertNeverPrompted();
    }

    public function test_an_active_reply_blocks_another_request_across_conversations(): void
    {
        MkposAssistant::fake(['Never call twice']);
        $this->stagePendingReply($this->conversation());
        $other = $this->conversation();
        $this->postJson('/api/ai-assistant/conversations/'.$other.'/messages',
            ['client_id' => (string) Str::uuid(), 'body' => 'Another question'])->assertConflict();
        $this->assertSame(1, DB::table('ai_runs')->count());
        MkposAssistant::assertNeverPrompted();
    }

    public function test_http_provider_failure_is_terminal_until_the_user_explicitly_retries(): void
    {
        $this->backgroundQueue();
        $calls = 0;
        MkposAssistant::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Fixture provider failure');
            }

            return 'Explicit retry succeeded';
        });
        $conversation = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$conversation.'/messages';
        $data = ['client_id' => (string) Str::uuid(), 'body' => 'Hi'];
        $this->postJson($url, $data)->assertStatus(503)->assertJsonPath('status', 'failed');
        $this->getJson($url.'/'.$data['client_id'].'/delivery')->assertOk()->assertJsonPath('status', 'failed');
        app(WyneWyneRunner::class)->process(DB::table('ai_runs')->value('id'));
        $this->assertSame(1, $calls);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('items.1.body', 'Explicit retry succeeded');
        $this->assertSame(2, $calls);
        $this->assertSame(2, DB::table('ai_runs')->count());
        $this->assertSame(1, DB::table('ai_messages')->where('role', 'assistant')->count());
        $this->assertSame(1, DB::table('ai_balance_entries')->where('type', 'usage')->count());
    }

    private function assistantRole(string $role): User
    {
        DB::table('users')->where('id', $this->owner->id)->update(['role' => $role]);
        $actor = $this->owner->fresh();
        $this->actingAs($actor, 'web');

        return $actor;
    }

    public function test_demotion_hides_owner_conversation_titles_messages_images_and_delivery_receipts(): void
    {
        Storage::fake('local');
        [$conversation, $message] = $this->supportMessage(true);
        $clientId = DB::table('ai_messages')->where('id', $message)->value('client_id');
        DB::table('ai_conversations')->where('id', $conversation)->update(['title' => 'Private owner report']);
        DB::table('ai_messages')->insert(['conversation_id' => $conversation, 'role' => 'assistant', 'body' => 'PRIVATE_OWNER_HISTORY', 'status' => 'completed', 'reply_to_id' => $message, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->assistantRole('staff');
        $base = '/api/ai-assistant/conversations/'.$conversation;
        $this->getJson('/api/ai-assistant/conversations')->assertOk()->assertJsonCount(0, 'items')->assertDontSee('Private owner report');
        $this->getJson($base.'/messages')->assertNotFound()->assertDontSee('PRIVATE_OWNER_HISTORY');
        $this->getJson($base.'/messages/'.$message.'/image')->assertNotFound();
        $this->getJson($base.'/messages/'.$clientId.'/delivery')->assertNotFound();
        // Reusing the client-owned UUID cannot relabel a protected conversation.
        $this->postJson('/api/ai-assistant/conversations', ['id' => $conversation, 'requires_owner' => false])->assertNotFound();
        $this->assertTrue((bool) DB::table('ai_conversations')->where('id', $conversation)->value('requires_owner'));
        Http::assertNothingSent();
    }

    public function test_demotion_blocks_completed_and_failed_message_replays_before_provider_usage(): void
    {
        $conversation = $this->conversation();
        $key = (string) Str::uuid();
        MkposAssistant::fake(['PRIVATE_OWNER_REPLY']);
        $body = ['client_id' => $key, 'body' => 'Show my report'];
        $url = '/api/ai-assistant/conversations/'.$conversation.'/messages';
        $this->postJson($url, $body)->assertOk();
        $before = DB::table('ai_balance_entries')->count();
        $this->assistantRole('staff');
        MkposAssistant::fake();
        $this->postJson($url, $body)->assertNotFound()->assertDontSee('PRIVATE_OWNER_REPLY');
        DB::table('ai_messages')->where('client_id', $key)->update(['status' => 'failed']);
        $this->postJson($url, $body)->assertNotFound();
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Repeat the earlier answer'])->assertNotFound();
        $this->assertSame(1, DB::table('ai_runs')->count());
        $this->assertSame($before, DB::table('ai_balance_entries')->count());
        MkposAssistant::assertPromptedTimes(1);
        Http::assertNothingSent();
    }

    public function test_staff_can_start_and_continue_safe_help_without_old_owner_data_in_provider_requests(): void
    {
        $private = $this->conversation();
        DB::table('ai_messages')->insert(['conversation_id' => $private, 'role' => 'assistant', 'body' => 'PRIVATE_OWNER_HISTORY', 'status' => 'completed', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $this->assistantRole('staff');
        $safe = $this->conversation();
        $this->assertFalse((bool) DB::table('ai_conversations')->where('id', $safe)->value('requires_owner'));
        $requests = [];
        Http::fake(['api.deepseek.com/*' => function ($request) use (&$requests) {
            $requests[] = $request['messages'];
            $this->assertStringNotContainsString('PRIVATE_OWNER_HISTORY', json_encode($request->data()));

            return Http::response(['id' => 'staff-fixture', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'SAFE_HELP_REPLY'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
        }]);
        $url = '/api/ai-assistant/conversations/'.$safe.'/messages';
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Explain the product form'])->assertOk();
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Explain the next field'])->assertOk();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('SAFE_HELP_REPLY', json_encode($requests[1]));
        $this->assertStringContainsString('Explain the product form', json_encode($requests[1]));
        $this->getJson('/api/ai-assistant/conversations')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $safe);
        $this->getJson($url)->assertOk()->assertJsonCount(4, 'items')->assertDontSee('PRIVATE_OWNER_HISTORY');
    }

    public function test_owner_use_permanently_protects_a_previously_staff_conversation(): void
    {
        $this->assistantRole('staff');
        $conversation = $this->conversation();
        $this->assertFalse((bool) DB::table('ai_conversations')->where('id', $conversation)->value('requires_owner'));
        $this->assistantRole('owner');
        MkposAssistant::fake(function () use ($conversation) {
            $this->assertTrue((bool) DB::table('ai_conversations')->where('id', $conversation)->value('requires_owner'));

            return 'PRIVATE_OWNER_REPLY';
        });
        $url = '/api/ai-assistant/conversations/'.$conversation.'/messages';
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Show my business report'])->assertOk();
        $this->assistantRole('staff');
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/ai-assistant/conversations')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_legacy_history_defaults_to_owner_only_when_privacy_migration_is_applied(): void
    {
        $migration = require database_path('migrations/2026_10_26_000002_protect_ai_conversation_history.php');
        $migration->down();
        $conversation = $this->conversation();
        DB::table('ai_messages')->insert(['conversation_id' => $conversation, 'role' => 'assistant', 'body' => 'PRIVATE_LEGACY_HISTORY', 'status' => 'completed', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $migration->up();
        $this->assertTrue((bool) DB::table('ai_conversations')->where('id', $conversation)->value('requires_owner'));
        $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertOk()->assertSee('PRIVATE_LEGACY_HISTORY');
        $this->assistantRole('staff');
        $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertNotFound();
    }

    public function test_staff_history_fails_closed_while_privacy_migration_is_pending(): void
    {
        (require database_path('migrations/2026_10_26_000002_protect_ai_conversation_history.php'))->down();
        $conversation = $this->conversation();
        $this->assistantRole('staff');
        $this->getJson('/api/ai-assistant/conversations')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertNotFound();
        $this->postJson('/api/ai-assistant/conversations', ['id' => (string) Str::uuid()])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_stale_owner_session_cannot_read_history_after_the_stored_role_changes(): void
    {
        $conversation = $this->conversation();
        DB::table('users')->where('id', $this->owner->id)->update(['role' => 'staff']);
        // Keep the cached authenticated model's old owner role deliberately.
        $this->assertSame('owner', $this->owner->role);
        $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertNotFound();
        $this->getJson('/api/ai-assistant/conversations')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_role_revocation_during_generation_suppresses_the_reply_but_accounts_for_incurred_usage(): void
    {
        $conversation = $this->conversation();
        MkposAssistant::fake(function () {
            DB::table('users')->where('id', $this->owner->id)->update(['role' => 'staff']);

            return 'PRIVATE_IN_FLIGHT_REPLY';
        });
        $url = '/api/ai-assistant/conversations/'.$conversation.'/messages';
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Show my owner report'])
            ->assertNotFound()->assertDontSee('PRIVATE_IN_FLIGHT_REPLY');
        $this->assertSame('failed', DB::table('ai_runs')->value('status'));
        $this->assertSame('access_changed', DB::table('ai_runs')->value('error_code'));
        $this->assertFalse(DB::table('ai_messages')->where('body', 'PRIVATE_IN_FLIGHT_REPLY')->exists());
        $this->assertSame(1, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        $this->assistantRole('staff');
        $this->getJson($url)->assertNotFound();
    }

    private function supportMessage(bool $image = false): array
    {
        $conversation = $this->conversation();
        $metadata = null;
        if ($image) {
            $file = UploadedFile::fake()->image('error.png', 80, 100);
            $metadata = app(AiChatImages::class)->inspect($file);
            $metadata['_image_path'] = $file->store('ai-chat-images/1/'.$conversation, 'local');
        }
        $message = DB::table('ai_messages')->insertGetId(['conversation_id' => $conversation, 'client_id' => Str::uuid(), 'role' => 'user', 'body' => 'Checkout fails with an unexpected error.',
            'attachment' => $metadata ? json_encode($metadata) : null, 'status' => 'completed', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return [$conversation, $message];
    }

    public function test_error_support_tool_escalates_once_with_owned_screenshot_and_returns_reference(): void
    {
        Storage::fake('local');
        [$conversation, $message] = $this->supportMessage(true);
        $agent = new MkposAssistant($this->owner, conversationId: $conversation, messageId: $message, app: 'business_mobile');
        $guide = json_decode((new ErrorSupportTool($agent, 'get_error_support_guide'))->handle(new Request([])), true);
        $this->assertSame($message, $guide['screenshots'][0]['message_id']);
        $this->assertSame('error_support', $guide['skill']);
        $tool = new ErrorSupportTool($agent, 'submit_error_report');
        $args = ['title' => 'Checkout error', 'description' => 'The checkout shows an unexpected error.', 'expected_result' => 'Complete the sale once.', 'attempted_steps' => 'Checked required payment fields; issue persists.', 'business_id' => 2];
        $first = json_decode($tool->handle(new Request($args)), true);
        $again = json_decode($tool->handle(new Request($args)), true);
        $this->assertSame('awaiting_owner_contact', $first['status']);
        $this->assertSame($first['request_id'], $again['request_id']);
        $this->assertSame(0, DB::table('error_reports')->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('error-report-images'));
        $this->getJson('/api/ai-assistant/error-report-requests/'.$first['request_id'])->assertOk()->assertJsonPath('title', 'Checkout error');
        $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertOk()->assertJsonPath('items.0.error_report_request.id', $first['request_id']);
        $this->postJson('/api/ai-assistant/error-report-requests/'.$first['request_id'].'/submit', [])->assertUnprocessable()->assertJsonValidationErrors('contact_choice');
        $this->postJson('/api/ai-assistant/error-report-requests/'.$first['request_id'].'/submit', ['contact_choice' => 'provided', 'contact_channel' => 'telegram', 'contact_value' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('contact_value');
        $first = $this->postJson('/api/ai-assistant/error-report-requests/'.$first['request_id'].'/submit', ['contact_choice' => 'provided', 'contact_channel' => 'telegram', 'contact_value' => '@shopowner', 'business_id' => 2])->assertOk()->assertJsonPath('reference', 'ERR-000001')->json();
        $again = json_decode($tool->handle(new Request($args)), true);
        $this->assertDatabaseHas('error_reports', ['contact_channel' => 'telegram', 'contact_value' => '@shopowner']);
        $this->assertSame('ERR-000001', $first['reference']);
        $this->assertFalse($first['already_submitted']);
        $this->assertTrue($again['already_submitted']);
        $this->assertSame(1, DB::table('error_reports')->count());
        $this->assertDatabaseHas('error_reports', ['business_id' => 1, 'user_id' => $this->owner->id, 'message_id' => $message, 'status' => 'open', 'app' => 'business_mobile']);
        $this->assertCount(1, Storage::disk('local')->allFiles('error-report-images'));
        $this->assertStringNotContainsString('_path', json_encode($first));
        $names = array_map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool), iterator_to_array((function () use ($agent) {
            yield from $agent->tools();
        })()));
        $this->assertContains('submit_error_report', $names);
        $this->assertStringContainsString('Never claim a bug is fixed without evidence', $agent->instructions());
        $this->getJson('/api/ai-assistant/error-reports/1')->assertOk()->assertJsonPath('report.reference', 'ERR-000001')->assertJsonMissingPath('report.internal_note');
    }

    public function test_error_report_owner_api_checks_context_and_cleans_up_rejected_screenshots(): void
    {
        Storage::fake('local');
        [$conversation, $message] = $this->supportMessage(true);
        [$otherConversation, $otherMessage] = $this->supportMessage(true);
        $body = ['conversation_id' => $otherConversation, 'message_id' => $otherMessage, 'title' => 'Error', 'description' => 'Still fails.', 'contact_choice' => 'skip', 'screenshot_message_ids' => [$otherMessage, $message]];
        $this->postJson('/api/ai-assistant/error-reports', $body)->assertNotFound();
        $this->assertCount(0, Storage::disk('local')->allFiles('error-report-images'));
        $this->assertSame(0, DB::table('error_reports')->count());
        $other = User::create(['business_id' => 2, 'name' => 'Other', 'email' => 'support-other@test.example', 'password' => 'password', 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($other, 'web')->postJson('/api/ai-assistant/error-reports', ['conversation_id' => $conversation, 'message_id' => $message, 'title' => 'Error', 'description' => 'Fails'])->assertNotFound();
        $other->update(['role' => 'staff']);
        $this->actingAs($other->fresh(), 'web')->getJson('/api/ai-assistant/error-reports')->assertForbidden();
    }

    public function test_report_contact_requests_are_private_and_contact_is_explicit_optional_and_validated(): void
    {
        [$conversation, $message] = $this->supportMessage();
        $service = app(ErrorReports::class);
        $draft = $service->prepare($this->owner, $conversation, $message, 'business_web', ['title' => 'Cannot print', 'description' => 'Printer error', 'contact_channel' => 'phone', 'contact_value' => 'guessed']);
        $path = '/api/ai-assistant/error-report-requests/'.$draft['request_id'];
        $other = User::create(['business_id' => 2, 'name' => 'Other', 'email' => 'contact-other@test.example', 'password' => 'password', 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($other, 'web')->getJson($path)->assertNotFound();
        $this->postJson($path.'/submit', ['contact_choice' => 'skip'])->assertNotFound();
        $this->actingAs($this->owner, 'web');
        foreach ([['email', 'owner@example.test'], ['facebook', 'https://evil.test/owner'], ['viber', 'abcdefg'], ['phone', '123']] as [$channel, $value]) {
            $this->postJson($path.'/submit', ['contact_choice' => 'provided', 'contact_channel' => $channel, 'contact_value' => $value])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('error_reports')->count());
        $this->postJson($path.'/submit', ['contact_choice' => 'skip', 'contact_channel' => 'phone', 'contact_value' => '+959111111111'])->assertOk()->assertJsonPath('already_submitted', false);
        $this->assertDatabaseHas('error_reports', ['message_id' => $message, 'contact_channel' => null, 'contact_value' => null]);
        $this->postJson($path.'/submit', ['contact_choice' => 'provided', 'contact_channel' => 'phone', 'contact_value' => '+959222222222'])->assertOk()->assertJsonPath('already_submitted', true);
        $this->assertSame(1, DB::table('error_reports')->count());
        $this->assertDatabaseHas('error_reports', ['message_id' => $message, 'contact_value' => null]);
        $this->getJson($path)->assertOk()->assertJsonPath('reference', 'ERR-000001');
    }

    public function test_all_four_supported_contact_channels_can_be_saved(): void
    {
        foreach (['facebook' => 'https://facebook.com/owner.profile', 'telegram' => '@ownername', 'viber' => '+95 9123456789', 'phone' => '09123456789'] as $channel => $value) {
            [$conversation, $message] = $this->supportMessage();
            $this->postJson('/api/ai-assistant/error-reports', ['conversation_id' => $conversation, 'message_id' => $message, 'title' => 'Error', 'description' => 'App error',
                'contact_choice' => 'provided', 'contact_channel' => $channel, 'contact_value' => $value])->assertCreated();
            $this->assertDatabaseHas('error_reports', ['message_id' => $message, 'contact_channel' => $channel, 'contact_value' => $value]);
        }
    }

    public function test_office_error_report_crud_preserves_private_notes_and_checks_versions(): void
    {
        Storage::fake('local');
        [$conversation, $message] = $this->supportMessage(true);
        $this->postJson('/api/ai-assistant/error-reports', ['conversation_id' => $conversation, 'message_id' => $message, 'title' => 'Checkout error', 'description' => 'Cannot complete checkout.', 'contact_choice' => 'provided', 'contact_channel' => 'phone', 'contact_value' => '+959123456789'])->assertCreated();
        $this->getJson('/api/office/error-reports')->assertUnauthorized();
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role')->default('admin');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('office_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id');
            $t->string('admin_name');
            $t->string('admin_email');
            $t->string('action');
            $t->string('method');
            $t->string('path');
            $t->text('details');
            $t->timestamp('created_at');
        });
        $admin = PlatformAdmin::create(['name' => 'Reviewer', 'email' => 'reviewer@test.example', 'password' => 'password', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');
        $list = $this->getJson('/api/office/error-reports?status=open&q=Checkout&per_page=10')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 10)->assertJsonMissingPath('items.0.screenshots.0._path');
        $imagePath = '/api'.$list->json('items.0.screenshots.0.path');
        $this->get($imagePath)->assertOk()->assertHeader('Content-Type', 'image/png');
        $row = $this->getJson('/api/office/error-reports/1')->assertOk()->assertJsonPath('contact_channel', 'phone')->assertJsonPath('contact_value', '+959123456789')->json();
        $update = array_merge($row, ['status' => 'resolved', 'priority' => 'high', 'resolution' => 'Payment fields corrected.', 'internal_note' => 'Private implementation detail']);
        $saved = $this->putJson('/api/office/error-reports/1', $update)->assertOk()->assertJsonPath('status', 'resolved')->json();
        $this->putJson('/api/office/error-reports/1', $update)->assertStatus(409);
        $this->actingAs($this->owner, 'web')->getJson('/api/ai-assistant/error-reports/1')->assertOk()->assertJsonPath('report.resolution', 'Payment fields corrected.')->assertDontSee('Private implementation detail');
        $this->actingAs($admin, 'office');
        $manual = ['business_id' => 2, 'title' => 'Manual support issue', 'description' => 'Admin recorded problem.', 'status' => 'open', 'priority' => 'normal', 'app' => 'unknown'];
        $this->postJson('/api/office/error-reports', $manual)->assertCreated()->assertJsonPath('source', 'office');
        $this->getJson('/api/office/error-reports?status=resolved')->assertJsonPath('total', 1);
        $this->deleteJson('/api/office/error-reports/1', ['version' => $saved['version']])->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles('error-report-images'));
        $this->assertCount(1, Storage::disk('local')->allFiles('ai-chat-images'));
        $this->get($imagePath)->assertNotFound();
        $this->assertSame(3, DB::table('office_activity_logs')->count());
    }

    public function test_wyne_wyne_identity_and_professional_soul_are_applied_to_owner_and_staff(): void
    {
        $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('name', 'Wyne Wyne')->assertJsonPath('name_my', WyneWyne::MYANMAR_NAME);
        foreach (['owner', 'staff'] as $role) {
            $actor = clone $this->owner;
            $actor->role = $role;
            $instructions = (new MkposAssistant($actor))->instructions();
            $this->assertStringContainsString('Your name is Wyne Wyne', $instructions);
            $this->assertStringContainsString(WyneWyne::MYANMAR_NAME, $instructions);
            $this->assertStringContainsString('female AI business and app assistant', $instructions);
            $this->assertStringContainsString('Be transparent that you are an AI assistant', $instructions);
            $this->assertStringContainsString('never assume their gender', $instructions);
            $this->assertStringContainsString('never in chat', $instructions);
            $this->assertStringContainsString('Never bypass owner approval', $instructions);
            $this->assertStringContainsString('Answer straight to the point', $instructions);
        }
    }

    public function test_agent_exchange_is_saved_and_idempotent_and_history_is_private(): void
    {
        MkposAssistant::fake(['မင်္ဂလာပါ။ MKPOS ကို ဘာကူညီပေးရမလဲ။']);
        $id = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $body = ['client_id' => (string) Str::uuid(), 'body' => 'Hello', 'language' => 'my'];
        $this->postJson($url, $body)->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.1.role', 'assistant');
        $this->postJson($url, $body)->assertOk();
        $this->assertSame(2, DB::table('ai_messages')->count());
        $this->assertSame(1, DB::table('ai_runs')->count());
        $this->getJson($url.'?limit=1')->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(1, 'items');
        $this->getJson($url.'?before='.DB::table('ai_messages')->max('id'))->assertOk()->assertJsonPath('items.0.body', 'Hello');
        $other = User::create(['business_id' => 1, 'name' => 'Coworker', 'email' => 'other@test.example', 'password' => 'test-password', 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($other, 'web');
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, $body)->assertNotFound();
        $this->getJson('/api/ai-assistant/conversations')->assertJsonCount(0, 'items');
        $other->business_id = 2;
        $other->save();
        $this->getJson($url)->assertNotFound();
    }

    public function test_provider_failure_preserves_message_retry_is_safe_and_attempts_are_limited(): void
    {
        MkposAssistant::fake(fn () => throw new \RuntimeException('secret-provider-error-test-key-never-return'));
        DB::table('ai_assistant_settings')->update(['weekly_limit' => 2]);
        $id = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $body = ['client_id' => (string) Str::uuid(), 'body' => 'Help'];
        $failure = $this->postJson($url, $body)->assertStatus(503);
        $this->assertStringNotContainsString('secret-provider-error', $failure->getContent());
        $this->assertSame('failed', DB::table('ai_messages')->first()->status);
        $this->postJson($url, array_merge($body, ['body' => 'Different']))->assertStatus(409);
        MkposAssistant::fake(['Try the Products screen.']);
        $this->postJson($url, $body)->assertOk();
        $this->assertSame(2, DB::table('ai_runs')->count());
        $this->assertSame(2, DB::table('ai_messages')->count());
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Third'])->assertStatus(429);
        $this->postJson($url, $body)->assertOk();
    }

    public function test_weekly_limit_spans_days_and_conversations_and_resets_at_yangon_monday(): void
    {
        DB::table('ai_assistant_settings')->update(['weekly_limit' => 1]);
        Cache::forever('ai.deepseek.pricing.v2', Cache::get('ai.deepseek.pricing.v2'));
        MkposAssistant::fake(['First reply.', 'New week reply.']);
        try {
            $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
            $first = $this->conversation();
            $this->postJson('/api/ai-assistant/conversations/'.$first.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Friday'])->assertOk();
            $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('weekly_usage.remaining', 0)->assertJsonPath('weekly_usage.remaining_percent', 0)->assertJsonPath('weekly_usage.resets_at', '2026-10-26T00:00:00+06:30');
            $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
            $second = $this->conversation();
            $url = '/api/ai-assistant/conversations/'.$second.'/messages';
            $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Saturday'])->assertStatus(429)->assertJsonPath('message', 'Your weekly AI message limit has been reached. It resets Monday at midnight Asia/Yangon.');
            $this->travelTo(Carbon::parse('2026-10-25 17:29:59', 'UTC'));
            $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Still Sunday'])->assertStatus(429);
            $this->travelTo(Carbon::parse('2026-10-25 17:30:00', 'UTC'));
            $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('weekly_usage.remaining', 1)->assertJsonPath('weekly_usage.remaining_percent', 100);
            $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Yangon Monday'])->assertOk();
            $this->assertSame(2, DB::table('ai_runs')->count());
            $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('weekly_limit', 1)->assertJsonMissingPath('daily_limit');
        } finally {
            $this->travelBack();
        }
    }

    public function test_weekly_remaining_credit_counts_failures_and_is_private_to_the_current_user(): void
    {
        DB::table('ai_assistant_settings')->update(['weekly_limit' => 4]);
        $id = $this->conversation();
        MkposAssistant::fake(fn () => throw new \RuntimeException('Provider unavailable'));
        $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Hi'])->assertStatus(503);
        $this->getJson('/api/ai-assistant/config?user_id=999')->assertOk()->assertJsonPath('weekly_usage.limit', 4)->assertJsonPath('weekly_usage.used', 1)->assertJsonPath('weekly_usage.remaining', 3)->assertJsonPath('weekly_usage.remaining_percent', 75);
        $this->getJson('/api/ai-assistant/conversations/'.$id.'/messages')->assertOk()->assertJsonPath('weekly_usage.remaining', 3);
        $other = User::create(['business_id' => 1, 'name' => 'Coworker', 'email' => 'quota@test.example', 'password' => 'test-password', 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($other, 'web');
        $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('weekly_usage.used', 0)->assertJsonPath('weekly_usage.remaining_percent', 100);
    }

    public function test_delivery_receipt_confirms_processing_before_reply_and_is_private(): void
    {
        $id = $this->conversation();
        $clientId = (string) Str::uuid();
        $url = '/api/ai-assistant/conversations/'.$id.'/messages/'.$clientId.'/delivery';
        $this->getJson($url)->assertOk()->assertJsonPath('sent', false)->assertJsonPath('seen', false)->assertJsonPath('message', null);
        MkposAssistant::fake(function () use ($url) {
            $this->getJson($url)->assertOk()->assertJsonPath('sent', true)->assertJsonPath('seen', true)->assertJsonPath('message.status', 'pending');
            $this->assertSame(0, DB::table('ai_messages')->where('role', 'assistant')->count());
            // The nested test request clears its tenant; resume the outer request context.
            app(TenantContext::class)->set((int) $this->owner->business_id);

            return 'Here is your reply.';
        });
        $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => $clientId, 'body' => 'Help'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('seen', true)->assertJsonPath('message.status', 'completed');
        $other = User::create(['business_id' => 1, 'name' => 'Other', 'email' => 'receipt-other@test.example', 'password' => 'test-password', 'role' => 'staff', 'is_active' => true]);
        $this->actingAs($other, 'web')->getJson($url)->assertNotFound();
    }

    public function test_csv_is_validated_without_imports_and_unsupported_files_are_rejected(): void
    {
        MkposAssistant::fake(['There is an invalid cost on row 2. Nothing was imported.']);
        $id = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $this->post($url, ['client_id' => (string) Str::uuid(), 'body' => 'Check this', 'csv' => UploadedFile::fake()->createWithContent('items.csv', "Name,Cost,Retail\nSoap,bad,100\n")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('items.0.attachment.validation.valid', false)->assertJsonPath('items.0.attachment.validation.writes_performed', false);
        $this->post($url, ['client_id' => (string) Str::uuid(), 'body' => 'Check', 'csv' => UploadedFile::fake()->createWithContent('items.xlsx', 'bad')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->getJson('/api/ai-assistant/product-template')->assertOk()->assertJsonPath('filename', 'mkpos-product-import-template.csv');
        $this->assertFalse(Schema::hasTable('products'));
    }

    public function test_follow_up_messages_keep_the_uploaded_validation_in_their_own_context(): void
    {
        MkposAssistant::fake(['Row 2 has an invalid cost.', 'Correct the Cost field.']);
        $id = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $this->post($url, ['client_id' => (string) Str::uuid(), 'body' => 'Check CSV', 'csv' => UploadedFile::fake()->createWithContent('followup.csv', "Name,Cost\nSoap,bad\n")], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'What should I correct?'])->assertOk();
        MkposAssistant::assertPrompted(fn ($prompt) => count($prompt->agent->history) === 2 && str_contains($prompt->agent->history[0]->content, 'Previously supplied CSV validation') && str_contains($prompt->agent->history[0]->content, 'followup.csv'));
    }

    private function imageReport(string $kind): array
    {
        return ['kind' => $kind, 'summary' => 'Visible MKPOS error screenshot.', 'payment_status' => 'unknown',
            ...array_fill_keys(['amount', 'currency', 'provider', 'recipient_name', 'recipient_account', 'transaction_reference', 'paid_at'], '')];
    }

    public function test_unknown_image_error_is_reported_to_office_once_and_unrelated_is_rejected(): void
    {
        Storage::fake('local');
        TelegramImageReader::fake([$this->imageReport('error_unknown'), $this->imageReport('unrelated')]);
        $url = '/api/ai-assistant/conversations/'.$this->conversation().'/messages';
        $key = (string) Str::uuid();
        $this->post($url, ['client_id' => $key, 'image' => UploadedFile::fake()->image('error.png')], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, DB::table('error_reports')->count());
        $this->assertSame(1, (int) DB::table('error_reports')->value('business_id'));
        $this->assertStringContainsString('submitted to the admin Office', DB::table('ai_messages')->where('role', 'assistant')->value('body'));
        $this->postJson($url, ['client_id' => $key, 'body' => ''])->assertOk();
        $this->assertSame(1, DB::table('error_reports')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('error-report-images'));
        $this->post($url, ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->image('unrelated.png')], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('This image is not related to the MKPOS system.', DB::table('ai_messages')->where('role', 'assistant')->orderByDesc('id')->value('body'));
        $this->assertSame(1, DB::table('error_reports')->count());
    }

    public function test_image_only_message_is_private_sent_to_the_agent_and_replay_does_not_upload_or_charge_twice(): void
    {
        Storage::fake('local');
        TelegramImageReader::fake([$this->imageReport('general')]);
        $id = $this->conversation();
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $key = (string) Str::uuid();
        $this->post($url, ['client_id' => $key, 'image' => UploadedFile::fake()->image('screen.png', 300, 400)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('items.0.attachment.type', 'image')->assertJsonPath('items.0.attachment.mime', 'image/png')->assertJsonPath('items.0.attachment.width', 300)
            ->assertJsonMissingPath('items.0.attachment._image_path')->assertJsonMissingPath('items.0.attachment._image_sha256');
        TelegramImageReader::assertPrompted(fn ($prompt) => count($prompt->attachments) === 1 && str_contains($prompt->prompt, 'Read the attached image'));
        $this->assertCount(1, Storage::disk('local')->allFiles('ai-chat-images'));
        $this->postJson($url, ['client_id' => $key, 'body' => ''])->assertOk();
        $this->assertSame(1, DB::table('ai_runs')->count());
        $history = $this->getJson($url)->assertOk()->assertJsonMissingPath('items.0.attachment._image_path');
        $previewPath = '/api'.$history->json('items.0.attachment.preview_path');
        $this->get($previewPath)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $otherConversation = $this->conversation();
        $this->get('/api/ai-assistant/conversations/'.$otherConversation.'/messages/'.$history->json('items.0.id').'/image')->assertNotFound();
        $this->post($url, ['client_id' => $key, 'image' => UploadedFile::fake()->image('different.png', 10, 10)], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertCount(1, Storage::disk('local')->allFiles('ai-chat-images'));
        $other = User::create(['business_id' => 2, 'name' => 'Other', 'email' => 'image-other@example.test', 'password' => 'password', 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($other, 'web')->getJson($url)->assertNotFound();
        $this->get($previewPath)->assertNotFound();
    }

    public function test_failed_image_message_can_retry_from_its_private_saved_image(): void
    {
        Storage::fake('local');
        TelegramImageReader::fake(fn () => throw new \RuntimeException('provider down'));
        $url = '/api/ai-assistant/conversations/'.$this->conversation().'/messages';
        $key = (string) Str::uuid();
        $this->post($url, ['client_id' => $key, 'body' => 'Read this', 'image' => UploadedFile::fake()->image('photo.jpg')], ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertCount(1, Storage::disk('local')->allFiles('ai-chat-images'));
        TelegramImageReader::fake([$this->imageReport('general')]);
        $this->postJson($url, ['client_id' => $key, 'body' => 'Read this'])->assertOk();
        TelegramImageReader::assertPrompted(fn ($prompt) => count($prompt->attachments) === 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('ai-chat-images'));
        $this->assertSame(2, DB::table('ai_messages')->count());
    }

    public function test_followup_uses_classified_text_without_resending_images_outside_policy(): void
    {
        Storage::fake('local');
        TelegramImageReader::fake([$this->imageReport('general'), $this->imageReport('general'), $this->imageReport('general')]);
        MkposAssistant::fake(['Here is the comparison']);
        $url = '/api/ai-assistant/conversations/'.$this->conversation().'/messages';
        for ($i = 0; $i < 3; $i++) {
            $this->post($url, ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->image('image-'.$i.'.png', 30 + $i, 40)], ['Accept' => 'application/json'])->assertOk();
        }
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Compare the recent images'])->assertOk();
        MkposAssistant::assertPrompted(function ($prompt) {
            if ($prompt->prompt !== 'Compare the recent images') {
                return false;
            }
            $images = 0;
            foreach ($prompt->agent->history as $message) {
                $this->assertStringNotContainsString('ai-chat-images/', $message->content);
                if ($message instanceof UserMessage) {
                    $images += $message->attachments->count();
                }
            }

            return $images === 0;
        });
    }

    public function test_unsupported_spoofed_oversized_and_mixed_attachments_are_rejected_before_provider_calls(): void
    {
        Storage::fake('local');
        MkposAssistant::fake();
        $url = '/api/ai-assistant/conversations/'.$this->conversation().'/messages';
        foreach ([UploadedFile::fake()->createWithContent('fake.png', '<script>bad</script>'),
            UploadedFile::fake()->image('animation.gif'), UploadedFile::fake()->image('large.jpg')->size(5121),
            UploadedFile::fake()->image('wide.png', 8193, 1)] as $image) {
            $this->post($url, ['client_id' => (string) Str::uuid(), 'image' => $image], ['Accept' => 'application/json'])->assertStatus(422);
        }
        $this->post($url, ['client_id' => (string) Str::uuid(), 'image' => UploadedFile::fake()->image('a.png'), 'csv' => UploadedFile::fake()->createWithContent('a.csv', "Name\nSoap")], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, DB::table('ai_messages')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        MkposAssistant::assertNeverPrompted();
    }

    public function test_disabled_and_unconfigured_states_do_not_call_the_provider_and_tools_are_read_only(): void
    {
        DB::table('ai_assistant_settings')->update(['enabled' => false]);
        $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('enabled', false)->assertDontSee('test-key-never-return');
        $this->postJson('/api/ai-assistant/conversations', ['id' => (string) Str::uuid()])->assertStatus(503);
        DB::table('ai_assistant_settings')->update(['enabled' => true]);
        config()->set('ai.providers.deepseek.key', null);
        $this->postJson('/api/ai-assistant/conversations', ['id' => (string) Str::uuid()])->assertStatus(503);
        $agent = new MkposAssistant($this->owner);
        $this->assertCount(27, iterator_to_array((function () use ($agent) {
            yield from $agent->tools();
        })()));
        $staff = clone $this->owner;
        $staff->role = 'staff';
        $agent = new MkposAssistant($staff);
        $this->assertSame(['MkposKnowledge', 'get_wyne_wyne_skills', 'open_app_screen'], collect($agent->tools())->map(fn ($tool) => method_exists($tool, 'name') ? $tool->name() : class_basename($tool))->all());
    }

    public function test_deepseek_transport_calls_a_real_sdk_tool_loop_without_network_or_writes(): void
    {
        // Public knowledge remains available to staff without the owner context step.
        $this->owner->role = 'staff';
        $this->owner->save();
        $calls = 0;
        Http::fake(['api.deepseek.com/*' => function ($request) use (&$calls) {
            $calls++;
            $this->assertSame('deepseek-flash', $request['model']);
            $this->assertSame('disabled', $request['thinking']['type']);
            $this->assertSame('auto', $request['tool_choice']);
            $message = $calls === 1 ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'tool-1', 'type' => 'function', 'function' => ['name' => $request['tools'][0]['function']['name'], 'arguments' => json_encode(['action' => 'get_product_import_template'])]]]] : ['role' => 'assistant', 'content' => 'Use Download CSV template to save the product template.'];

            return Http::response(['id' => 'response-'.$calls, 'object' => 'chat.completion', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $calls === 1 ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30, 'prompt_cache_hit_tokens' => $calls === 1 ? 6 : 2]]);
        }]);
        $id = $this->conversation();
        $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Give me a product template'])->assertOk()->assertJsonPath('items.1.tools.0', 'get_product_import_template');
        $this->assertSame(2, $calls);
        $this->assertSame(8, (int) DB::table('ai_runs')->value('cached_input_tokens'));
        $this->assertSame(8, (int) DB::table('ai_messages')->where('role', 'assistant')->value('cached_input_tokens'));
        $this->assertSame(-51648, (int) DB::table('ai_balance_entries')->where('type', 'usage')->value('amount_nanos'));
        $this->assertFalse(Schema::hasTable('products'));
    }

    private function officeAdmin(): PlatformAdmin
    {
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('office_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id');
            $t->string('admin_name');
            $t->string('admin_email');
            $t->string('action');
            $t->string('method');
            $t->string('path');
            $t->json('details');
            $t->timestamp('created_at');
        });
        $admin = PlatformAdmin::create(['name' => 'Admin', 'email' => 'admin@test.example', 'password' => 'test-password', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'office');

        return $admin;
    }

    public function test_office_usd_to_mmk_rate_is_optional_validated_persisted_and_super_admin_only(): void
    {
        $admin = $this->officeAdmin();
        $body = ['enabled' => false, 'model' => 'deepseek-flash', 'weekly_limit' => 50, 'max_tokens' => 1600];
        $url = '/api/office/ai-assistant';
        $this->putJson($url, $body + ['usd_to_mmk_rate' => '4200.125'])->assertForbidden();
        $admin->update(['role' => 'super_admin']);
        $this->getJson($url)->assertOk()->assertJsonPath('settings.usd_to_mmk_rate', null);
        $this->putJson($url, $body + ['usd_to_mmk_rate' => '4200.125'])->assertOk()->assertJsonPath('settings.usd_to_mmk_rate', '4200.125');
        $details = json_decode(DB::table('office_activity_logs')->orderByDesc('id')->value('details'), true);
        $this->assertSame('4200.125', $details['usd_to_mmk_rate']);
        foreach (['0', '-1', 'bad', '9999999999', '1.1234567'] as $invalid) {
            $this->putJson($url, $body + ['usd_to_mmk_rate' => $invalid])->assertStatus(422)->assertJsonValidationErrors('usd_to_mmk_rate');
        }
        $this->putJson($url, $body)->assertOk()->assertJsonPath('settings.usd_to_mmk_rate', '4200.125');
        $this->getJson($url)->assertOk()->assertJsonPath('settings.usd_to_mmk_rate', '4200.125');
        $this->putJson($url, $body + ['usd_to_mmk_rate' => null])->assertOk()->assertJsonPath('settings.usd_to_mmk_rate', null);
        $this->assertSame('10.000000000', AiBalanceBook::amount((int) DB::table('ai_balance_accounts')->value('balance_nanos')));
        Http::assertNothingSent();
    }

    public function test_sales_ai_markup_defaults_to_five_and_is_validated_persisted_and_audited(): void
    {
        $admin = $this->officeAdmin();
        $body = ['enabled' => false, 'model' => 'deepseek-flash', 'weekly_limit' => 50, 'max_tokens' => 1600];
        $url = '/api/office/ai-assistant';
        $this->putJson($url, $body + ['sales_ai_markup_percent' => '7.50'])->assertForbidden();
        $admin->update(['role' => 'super_admin']);
        $this->getJson($url)->assertOk()->assertJsonPath('settings.sales_ai_markup_percent', '5.00');
        $this->putJson($url, $body + ['sales_ai_markup_percent' => '7.50'])->assertOk()->assertJsonPath('settings.sales_ai_markup_percent', '7.50');
        $details = json_decode(DB::table('office_activity_logs')->orderByDesc('id')->value('details'), true);
        $this->assertSame('7.50', $details['sales_ai_markup_percent']);
        foreach (['-1', 'bad', '1001', '1.123', null] as $invalid) {
            $this->putJson($url, $body + ['sales_ai_markup_percent' => $invalid])->assertStatus(422)->assertJsonValidationErrors('sales_ai_markup_percent');
        }
        $this->putJson($url, $body)->assertOk()->assertJsonPath('settings.sales_ai_markup_percent', '7.50');
        $this->putJson($url, $body + ['sales_ai_markup_percent' => '0'])->assertOk()->assertJsonPath('settings.sales_ai_markup_percent', '0.00');
        $this->assertSame('10.000000000', AiBalanceBook::amount((int) DB::table('ai_balance_accounts')->value('balance_nanos')));
        Http::assertNothingSent();
    }

    public function test_office_saves_pricing_and_assistant_settings_independently(): void
    {
        $admin = $this->officeAdmin();
        $admin->update(['role' => 'super_admin']);
        $before = DB::table('ai_assistant_settings')->where('id', 1)->first();
        $this->putJson('/api/office/ai-assistant', ['usd_to_mmk_rate' => '4200', 'sales_ai_markup_percent' => '7.5'])
            ->assertOk()->assertJsonPath('settings.sales_ai_markup_percent', '7.50');
        $after = DB::table('ai_assistant_settings')->where('id', 1)->first();
        $this->assertSame($before->model, $after->model);
        $this->assertSame($before->enabled, $after->enabled);
        $this->putJson('/api/office/ai-assistant', ['weekly_limit' => 30])->assertOk()->assertJsonPath('settings.weekly_limit', 30)
            ->assertJsonPath('settings.sales_ai_markup_percent', '7.50');
        $this->putJson('/api/office/ai-assistant', ['model' => ''])->assertUnprocessable();
        $this->putJson('/api/office/ai-assistant', ['sales_ai_markup_percent' => -1])->assertUnprocessable();
    }

    public function test_api_keys_are_super_admin_only_encrypted_and_never_returned_or_audited(): void
    {
        $key = 'sk-office-private-example-key';
        $admin = $this->officeAdmin();
        $this->putJson('/api/office/ai-assistant/api-key', ['api_key' => $key])->assertForbidden();
        $this->postJson('/api/office/ai-assistant/test-connection', ['api_key' => $key])->assertForbidden();
        Http::assertNothingSent();
        $admin->update(['role' => 'super_admin']);
        $this->putJson('/api/office/ai-assistant/api-key', ['api_key' => $key])->assertOk()->assertJsonPath('settings.configured', true)->assertJsonMissingPath('settings.api_key_encrypted')->assertDontSee($key);
        $encrypted = DB::table('ai_assistant_settings')->value('api_key_encrypted');
        $this->assertNotSame($key, $encrypted);
        $this->assertSame($key, Crypt::decryptString($encrypted));
        $this->assertStringNotContainsString($key, json_encode(DB::table('office_activity_logs')->get()));
        $this->actingAs($this->owner, 'web');
        $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('configured', true)->assertDontSee($key)->assertDontSee($encrypted);
        app(AiProviderCredentials::class)->activate();
        $this->assertSame($key, config('ai.providers.deepseek.key'));
        DB::table('ai_assistant_settings')->update(['api_key_encrypted' => null]);
        $this->assertSame('test-key-never-return', app(AiProviderCredentials::class)->key());
    }

    public function test_connection_checks_saved_or_candidate_key_without_saving_and_sanitizes_failures(): void
    {
        $admin = $this->officeAdmin();
        $admin->update(['role' => 'super_admin']);
        $saved = 'sk-saved-private-example-key';
        $candidate = 'sk-candidate-private-example-key';
        app(AiProviderCredentials::class)->replace($saved);
        $failure = 0;
        Http::fake(['api.deepseek.com/models' => function () use (&$failure, $candidate) {
            if ($failure === 2) {
                throw new \RuntimeException($candidate);
            }

            return $failure === 1 ? Http::response(['error' => $candidate], 401) : Http::response(['data' => [['id' => 'deepseek-flash']]]);
        }]);
        $this->postJson('/api/office/ai-assistant/test-connection')->assertOk()->assertJsonPath('message', 'Connection successful.')->assertDontSee($saved);
        $this->postJson('/api/office/ai-assistant/test-connection', ['api_key' => $candidate])->assertOk()->assertDontSee($candidate);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer '.$saved));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$candidate));
        $this->assertSame($saved, app(AiProviderCredentials::class)->key());
        $failure = 1;
        $this->postJson('/api/office/ai-assistant/test-connection', ['api_key' => $candidate])->assertStatus(422)->assertDontSee($candidate);
        $failure = 2;
        $this->postJson('/api/office/ai-assistant/test-connection', ['api_key' => $candidate])->assertStatus(503)->assertDontSee($candidate);
        $this->assertStringNotContainsString($candidate, json_encode(DB::table('office_activity_logs')->get()));
    }

    public function test_chat_uses_replaced_key_even_when_sdk_provider_was_already_resolved(): void
    {
        app(AiManager::class)->textProvider('deepseek');
        app(AiProviderCredentials::class)->replace('sk-runtime-replacement-key');
        Http::fake(['api.deepseek.com/*' => Http::response(['id' => 'reply', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]])]);
        $id = $this->conversation();
        $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Hi'])->assertOk();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-runtime-replacement-key'));
    }

    public function test_credentials_preserve_an_empty_environment_fallback_and_fail_closed_on_invalid_ciphertext(): void
    {
        config()->set('ai.providers.deepseek.key', null);
        $credentials = app(AiProviderCredentials::class);
        $credentials->replace('sk-office-private-example-key');
        $credentials->activate();
        $credentials->activate();
        DB::table('ai_assistant_settings')->update(['api_key_encrypted' => null]);
        $this->assertNull($credentials->key());
        DB::table('ai_assistant_settings')->update(['api_key_encrypted' => 'invalid-ciphertext']);
        $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('configured', false)->assertDontSee('invalid-ciphertext');
    }

    public function test_office_settings_require_a_super_admin_and_never_return_credentials(): void
    {
        Http::fake([
            DeepSeekPricing::SOURCE => Http::response(file_get_contents(base_path('tests/Fixtures/deepseek-pricing.html'))),
        ]);
        $admin = $this->officeAdmin();
        $this->getJson('/api/office/ai-assistant')->assertForbidden();
        Http::assertNothingSent();
        $admin->role = 'super_admin';
        $admin->save();
        $this->getJson('/api/office/ai-assistant')->assertOk()->assertJsonPath('pricing.rates.deepseek-flash.input', 0.3)
            ->assertJsonMissingPath('balance')
            ->assertJsonPath('usage_charts.timezone', AiUsageReport::TIMEZONE)->assertJsonCount(24, 'usage_charts.series.hourly')
            ->assertJsonCount(30, 'usage_charts.series.daily')->assertJsonCount(12, 'usage_charts.series.monthly')
            ->assertJsonCount(5, 'usage_charts.series.yearly')->assertDontSee('test-key-never-return');
        $this->putJson('/api/office/ai-assistant', ['enabled' => true, 'model' => 'deepseek-flash', 'weekly_limit' => 25, 'max_tokens' => 1000])->assertOk()->assertJsonPath('settings.weekly_limit', 25);
        $this->assertSame(1, DB::table('office_activity_logs')->count());
        config()->set('ai.providers.deepseek.key', null);
        $this->putJson('/api/office/ai-assistant', ['enabled' => true, 'model' => 'deepseek-flash', 'weekly_limit' => 25, 'max_tokens' => 1000])->assertStatus(422);
    }

    public function test_zero_or_negative_balance_blocks_new_requests_across_businesses_without_calling_ai(): void
    {
        MkposAssistant::fake(['This must never be generated.']);
        $one = $this->conversation();
        $other = User::create(['business_id' => 2, 'name' => 'Other owner', 'email' => 'two@test.example', 'password' => 'test-password', 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($other, 'web');
        $two = $this->conversation();
        foreach ([0, -1] as $balance) {
            DB::table('ai_balance_accounts')->update(['balance_nanos' => $balance]);
            foreach ([[$this->owner, $one], [$other, $two]] as [$user, $conversation]) {
                $this->actingAs($user, 'web');
                $this->postJson('/api/ai-assistant/conversations/'.$conversation.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Help'])->assertStatus(402)->assertSee('system AI balance is empty');
                $this->postJson('/api/ai-assistant/conversations', ['id' => (string) Str::uuid()])->assertStatus(402);
                $this->getJson('/api/ai-assistant/conversations/'.$conversation.'/messages')->assertOk();
            }
        }
        $this->assertSame(0, DB::table('ai_runs')->count());
        MkposAssistant::assertNeverPrompted();
    }

    public function test_top_ups_are_super_admin_only_idempotent_and_resume_system_usage(): void
    {
        DB::table('ai_balance_accounts')->update(['balance_nanos' => 0]);
        $admin = $this->officeAdmin();
        $url = '/api/office/ai-balance-book';
        $body = ['request_key' => (string) Str::uuid(), 'amount' => '5.123456', 'description' => 'October internal budget', 'reference' => 'ALLOC-1'];
        $this->getJson($url)->assertForbidden();
        $this->postJson($url.'/top-ups', $body)->assertForbidden();
        $admin->update(['role' => 'super_admin']);
        $this->postJson($url.'/top-ups', $body)->assertOk()->assertJsonPath('entry.amount', '5.123456000');
        $this->postJson($url.'/top-ups', $body)->assertOk();
        $this->assertSame(1, DB::table('ai_balance_entries')->where('request_key', $body['request_key'])->count());
        $this->postJson($url.'/top-ups', array_replace($body, ['amount' => '6']))->assertStatus(409);
        foreach (['0', '-1', '1.1234567', '1000001', 'bad'] as $amount) {
            $this->postJson($url.'/top-ups', array_replace($body, ['request_key' => (string) Str::uuid(), 'amount' => $amount]))->assertStatus(422);
        }
        $this->getJson($url.'?q=ALLOC-1&per_page=10&page=90')->assertOk()->assertJsonPath('balance', '5.123456000')->assertJsonPath('can_use_ai', true)->assertJsonPath('page', 1)->assertJsonCount(1, 'items');
        $this->actingAs($this->owner, 'web');
        MkposAssistant::fake(['The top-up restored access.']);
        $conversation = $this->conversation();
        $this->postJson('/api/ai-assistant/conversations/'.$conversation.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Hello'])->assertOk();
    }

    public function test_charges_once_at_captured_rates_and_blocks_the_next_request_when_exhausted(): void
    {
        $id = $this->conversation();
        DB::table('ai_balance_accounts')->update(['balance_nanos' => 1]);
        Http::fake(['api.deepseek.com/*' => Http::response(['id' => 'reply', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100]])]);
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $body = ['client_id' => (string) Str::uuid(), 'body' => 'Hello'];
        $this->postJson($url, $body)->assertOk();
        $entry = DB::table('ai_balance_entries')->where('type', 'usage')->first();
        $this->assertSame(-420000, (int) $entry->amount_nanos);
        $this->assertSame(-419999, (int) $entry->balance_after_nanos);
        $this->postJson($url, $body)->assertOk();
        $this->assertSame(1, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        $this->assertSame(1, DB::table('ai_runs')->count());
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Next'])->assertStatus(402);
        $old = json_decode($entry->pricing, true);
        DB::transaction(fn () => app(AiBalanceBook::class)->charge($entry->run_id, 999, 999, $old));
        $this->assertSame(-419999, (int) DB::table('ai_balance_accounts')->value('balance_nanos'));
        $this->assertSame(300000000, $old['input_rate_nanos']);
        Http::assertSentCount(1);
    }

    public function test_missing_prices_and_provider_failures_do_not_spend_internal_credit(): void
    {
        $id = $this->conversation();
        DB::table('ai_assistant_settings')->update(['model' => 'custom-model']);
        MkposAssistant::fake(['No pricing should prevent this response.']);
        $url = '/api/ai-assistant/conversations/'.$id.'/messages';
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Help'])->assertStatus(503)->assertSee('pricing is currently unavailable');
        MkposAssistant::assertNeverPrompted();
        DB::table('ai_assistant_settings')->update(['model' => 'deepseek-flash']);
        MkposAssistant::fake(fn () => throw new \RuntimeException('Provider outage'));
        $this->postJson($url, ['client_id' => (string) Str::uuid(), 'body' => 'Help'])->assertStatus(503);
        $this->assertSame(0, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        $this->assertSame(10000000000, (int) DB::table('ai_balance_accounts')->value('balance_nanos'));
    }

    public function test_usage_charge_rolls_back_with_a_failed_reply_transaction(): void
    {
        $run = (string) Str::uuid();
        DB::table('ai_runs')->insert(['id' => $run, 'user_id' => $this->owner->id, 'business_id' => 1, 'model' => 'deepseek-flash', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $book = app(AiBalanceBook::class);
        $pricing = $book->pricing('deepseek-flash');
        try {
            DB::transaction(function () use ($book, $run, $pricing) {
                $book->charge($run, 1000, 100, $pricing);
                throw new \RuntimeException('Simulated reply persistence failure');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated reply persistence failure', $error->getMessage());
        }
        $this->assertSame(0, DB::table('ai_balance_entries')->where('run_id', $run)->count());
        $this->assertSame(10000000000, (int) DB::table('ai_balance_accounts')->value('balance_nanos'));
    }

    public function test_cache_hits_reduce_deductions_and_unknown_usage_keeps_the_uncached_estimate(): void
    {
        $id = $this->conversation();
        $usage = [];
        Http::fake(['api.deepseek.com/*' => function () use (&$usage) {
            return Http::response(['id' => 'reply', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']], 'usage' => $usage]);
        }]);
        foreach ([[0, 420000], [800, 184800], [1000, 126000], [null, 420000]] as [$cached, $expected]) {
            $usage = ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100];
            if ($cached !== null) {
                $usage['prompt_cache_hit_tokens'] = $cached;
            }
            $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Hello'])->assertOk()
                ->assertJsonPath('items.1.cached_input_tokens', $cached);
            $entry = DB::table('ai_balance_entries')->where('type', 'usage')->orderByDesc('id')->first();
            $this->assertSame(-$expected, (int) $entry->amount_nanos);
            $this->assertSame($cached, $entry->cached_input_tokens === null ? null : (int) $entry->cached_input_tokens);
            $this->assertSame(6000000, json_decode($entry->pricing, true)['cached_input_rate_nanos']);
        }
    }

    public function test_invalid_provider_cache_usage_does_not_create_a_discount_or_charge(): void
    {
        $id = $this->conversation();
        Http::fake(['api.deepseek.com/*' => Http::response(['id' => 'reply', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'prompt_cache_hit_tokens' => 101]])]);
        $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Hello'])->assertStatus(503);
        $this->assertSame(0, DB::table('ai_balance_entries')->where('type', 'usage')->count());
        $this->assertSame(10000000000, (int) DB::table('ai_balance_accounts')->value('balance_nanos'));
    }

    public function test_ai_balance_summary_is_super_admin_only_and_reads_internal_credit_without_provider_calls(): void
    {
        $admin = $this->officeAdmin();
        $this->getJson('/api/office/ai-balance-book/summary')->assertForbidden();
        $admin->update(['role' => 'super_admin']);
        $this->getJson('/api/office/ai-balance-book/summary')->assertOk()->assertJsonPath('currency', 'USD')
            ->assertJsonPath('balance', '10.000000000')->assertJsonPath('can_use_ai', true)->assertJsonMissingPath('items');
        DB::table('ai_balance_accounts')->update(['balance_nanos' => -1]);
        $this->getJson('/api/office/ai-balance-book/summary')->assertOk()->assertJsonPath('balance', '-0.000000001')->assertJsonPath('can_use_ai', false);
        Http::assertNothingSent();
    }

    public function test_balance_book_average_uses_all_time_usage_not_top_ups_search_or_pagination(): void
    {
        $admin = $this->officeAdmin();
        $admin->update(['role' => 'super_admin']);
        $url = '/api/office/ai-balance-book';
        $this->getJson($url)->assertOk()->assertJsonPath('total_requests', 0)->assertJsonPath('average_cost_per_100_requests', null);
        $this->getJson($url.'/summary')->assertOk()->assertJsonPath('total_requests', 0)->assertJsonPath('average_cost_per_100_requests', null);
        foreach ([['2025-01-01', -1000000], ['2026-10-25', -3000000], ['2026-10-25', 0]] as [$date, $amount]) {
            DB::table('ai_balance_entries')->insert(['type' => 'usage', 'amount_nanos' => $amount, 'balance_after_nanos' => 0, 'description' => 'Request', 'run_id' => (string) Str::uuid(), 'created_at' => $date.' 00:00:00']);
        }
        $this->getJson($url.'?q=opening&per_page=10&page=50')->assertOk()
            ->assertJsonPath('total_requests', 3)->assertJsonPath('total_usage', '0.004000000')
            ->assertJsonPath('average_cost_per_100_requests', '0.133333333');
        $this->getJson($url.'/summary')->assertOk()->assertJsonPath('total_requests', 3)->assertJsonPath('average_cost_per_100_requests', '0.133333333');
        Http::assertNothingSent();
    }

    public function test_subscription_gate_blocks_expired_ai_calls_without_charging_and_preserves_history(): void
    {
        $id = $this->conversation();
        $before = DB::table('ai_balance_entries')->count();
        foreach (['expired', 'no_subscription', 'not_started'] as $reason) {
            $this->mock(SubscriptionService::class, fn ($m) => $m->shouldReceive('status')->andReturn(['is_valid' => false, 'access_type' => $reason === 'no_subscription' ? null : 'trial', 'reason' => $reason]));
            MkposAssistant::fake();
            $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('subscription_access.allowed', false)->assertJsonPath('subscription_access.reason', $reason);
            $this->postJson('/api/ai-assistant/conversations', ['id' => (string) Str::uuid(), 'business_id' => 2])->assertStatus(402)->assertJsonPath('code', 'assistant_subscription_required');
            $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Hello', 'image' => null])->assertStatus(402);
            $this->getJson('/api/ai-assistant/conversations/'.$id.'/messages')->assertOk();
            MkposAssistant::assertNeverPrompted();
        }
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_runs', 0);
        $this->assertSame($before, DB::table('ai_balance_entries')->count());
        $this->mock(SubscriptionService::class, fn ($m) => $m->shouldReceive('status')->andReturn(['is_valid' => true, 'can_mutate' => true, 'access_type' => 'trial', 'reason' => null]));
        $this->getJson('/api/ai-assistant/config')->assertOk()->assertJsonPath('subscription_access.allowed', true);
        MkposAssistant::fake(['Trial access is active.']);
        $this->postJson('/api/ai-assistant/conversations/'.$id.'/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Help'])->assertOk();
    }
}
