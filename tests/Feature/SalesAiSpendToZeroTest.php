<?php

namespace Tests\Feature;

use App\Services\AiAssistantSettings;
use App\Services\AiProviderCredentials;
use App\Services\EcommerceService;
use App\Services\SalesAi\Pricing;
use App\Services\SalesAi\Runner;
use App\Services\SalesAi\Settings;
use App\Services\SalesAi\Wallet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAiSpendToZeroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.spend_to_zero' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'shop_chat.realtime_enabled' => false, 'ai.providers.deepseek.key' => 'fixture']);
        DB::setDefaultConnection('spend_to_zero');
        Http::preventStrayRequests();
        Schema::create('businesses', fn (Blueprint $t) => $t->id());
        Schema::create('ai_assistant_settings', fn (Blueprint $t) => $t->id());
        Schema::create('shop_chat_threads', function (Blueprint $t) {
            $t->id(); $t->integer('business_id'); $t->string('phone'); $t->integer('platform_customer_id')->nullable(); $t->timestamps();
        });
        Schema::create('shop_chat_messages', function (Blueprint $t) {
            $t->id(); $t->integer('thread_id'); $t->string('client_id'); $t->string('sender'); $t->text('body');
            $t->string('image_path')->nullable(); $t->string('image_mime')->nullable();
            $t->timestamp('image_deleted_at')->nullable(); $t->timestamp('image_expires_at')->nullable();
            $t->text('product_snapshot')->nullable(); $t->timestamp('created_at');
        });
        Schema::create('shop_customer_controls', function (Blueprint $t) {
            $t->integer('business_id'); $t->boolean('blocked'); $t->string('customer_key'); $t->string('phone');
        });
        (require database_path('migrations/2026_10_25_000015_create_sales_ai.php'))->up();
        (require database_path('migrations/2026_10_25_000002_create_ai_balance_book_tables.php'))->up();
        Schema::table('ai_balance_entries', fn (Blueprint $t) => $t->integer('cached_input_tokens')->nullable());
        DB::table('businesses')->insert(['id' => 1]);
        DB::table('ai_assistant_settings')->insert(['id' => 1]);
        DB::table('ai_balance_accounts')->where('id', 1)->update(['balance_nanos' => 1_000_000_000]);
        DB::table('shop_chat_threads')->insert(['id' => 1, 'business_id' => 1, 'phone' => '0912345678']);
        app(Wallet::class)->ensure(1);
        $this->mock(Settings::class)->shouldReceive('get')->with(1)->andReturn([
            'enabled' => true, 'version' => 1, 'name' => 'Mia', 'instructions' => '', 'soul' => '', 'tool_permissions' => [],
        ]);
        $this->mock(EcommerceService::class)->shouldReceive('eligible')->with(1)->andReturn(true);
        $this->mock(AiAssistantSettings::class)->shouldReceive('get')->andReturn(['enabled' => true]);
        $this->mock(AiProviderCredentials::class)->shouldReceive('activate')->andReturnNull();
        $this->partialMock(Pricing::class)->shouldReceive('snapshot')->andReturn([
            'input_rate_nanos' => 300_000_000, 'output_rate_nanos' => 1_200_000_000, 'cached_input_rate_nanos' => 6_000_000,
            'usd_to_mmk_rate' => '4800', 'markup_percent' => '10', 'model' => Pricing::MODEL,
        ]);
    }

    private function response(): array
    {
        return ['id' => 'reply', 'object' => 'chat.completion', 'model' => Pricing::MODEL,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'How can I help?'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100, 'prompt_cache_hit_tokens' => 200]];
    }

    private function createRun(int $balance): string
    {
        DB::table('sales_ai_wallets')->where('business_id', 1)->update(['balance_micros' => $balance]);
        $message = DB::table('shop_chat_messages')->insertGetId(['thread_id' => 1, 'client_id' => (string) Str::uuid(), 'sender' => 'customer', 'body' => 'Hello', 'created_at' => now('UTC')]);
        $id = (string) Str::uuid();
        DB::table('sales_ai_runs')->insert(['id' => $id, 'business_id' => 1, 'thread_id' => 1, 'trigger_message_id' => $message,
            'thread_version' => 1, 'settings_version' => 1, 'agent_name' => 'Mia', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return $id;
    }

    public function test_reported_balance_can_reply_without_a_reserve_and_is_charged_actual_usage_once(): void
    {
        $id = $this->createRun(434_636_300);
        Http::fake(['api.deepseek.com/*' => function () {
            $this->assertSame(0, (int) DB::table('sales_ai_wallets')->value('reserved_micros'));
            $this->assertSame(434_636_300, (int) DB::table('sales_ai_wallets')->value('balance_micros'));

            return Http::response($this->response());
        }]);
        app(Runner::class)->process($id);
        app(Runner::class)->process($id);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $id, 'status' => 'completed', 'charge_micros' => 1_907_136, 'reserved_micros' => 0]);
        $this->assertSame(432_729_164, (int) DB::table('sales_ai_wallets')->value('balance_micros'));
        $this->assertSame(1, DB::table('sales_ai_entries')->where('run_id', $id)->count());
        $this->assertDatabaseHas('shop_chat_messages', ['sales_ai_run_id' => $id, 'body' => 'How can I help?']);
        Http::assertSentCount(1);
    }

    public function test_last_reply_is_delivered_and_capped_at_zero_with_full_provider_cost_recorded(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->response())]);
        $id = $this->createRun(1);
        $next = $this->createRun(1);
        app(Runner::class)->process($id);
        app(Runner::class)->process($id);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $id, 'status' => 'completed', 'charge_micros' => 1]);
        $this->assertDatabaseHas('sales_ai_wallets', ['business_id' => 1, 'balance_micros' => 0, 'reserved_micros' => 0]);
        $this->assertDatabaseHas('shop_chat_messages', ['sales_ai_run_id' => $id, 'body' => 'How can I help?']);
        $this->assertDatabaseHas('ai_balance_entries', ['run_id' => $id, 'amount_nanos' => -361_200]);
        $this->assertDatabaseHas('sales_ai_entries', ['run_id' => $id, 'amount_micros' => -1]);
        $meta = json_decode(DB::table('sales_ai_entries')->where('run_id', $id)->value('pricing'), true);
        $this->assertSame(1_907_135, $meta['unbilled_amount_micros']);

        $this->assertSame(1, DB::table('sales_ai_entries')->where('run_id', $id)->count());
        app(Runner::class)->process($next);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $next, 'status' => 'failed', 'reason' => 'credit_required']);
        Http::assertSentCount(1);
    }

    public function test_zero_balance_does_not_contact_the_provider(): void
    {
        $id = $this->createRun(0);
        app(Runner::class)->process($id);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $id, 'status' => 'failed', 'reason' => 'credit_required']);
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
        Http::assertNothingSent();
    }

    public function test_legacy_hold_can_be_released_without_charging_or_blocking_future_replies(): void
    {
        $id = $this->createRun(10_000_000);
        DB::table('sales_ai_runs')->where('id', $id)->update(['status' => 'processing', 'reserved_micros' => 10_000_000]);
        DB::table('sales_ai_wallets')->where('business_id', 1)->update(['reserved_micros' => 10_000_000]);
        app(Runner::class)->fail($id, 'worker_interrupted');
        $this->assertDatabaseHas('sales_ai_wallets', ['balance_micros' => 10_000_000, 'reserved_micros' => 0]);
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
        Http::assertNothingSent();
    }

    public function test_another_processing_run_blocks_a_new_call_even_without_the_cache_lock(): void
    {
        $first = $this->createRun(10_000_000);
        $second = $this->createRun(10_000_000);
        DB::table('sales_ai_runs')->where('id', $first)->update(['status' => 'processing']);
        $this->assertFalse(app(Runner::class)->process($second));
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $second, 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_provider_failure_does_not_charge_the_wallet(): void
    {
        $id = $this->createRun(1);
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);
        app(Runner::class)->process($id);
        $this->assertDatabaseHas('sales_ai_wallets', ['balance_micros' => 1, 'reserved_micros' => 0]);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $id, 'status' => 'failed']);
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
    }

    public function test_human_takeover_suppresses_the_reply_without_charging_the_business(): void
    {
        $id = $this->createRun(1);
        Http::fake(['api.deepseek.com/*' => function () {
            DB::table('shop_chat_threads')->where('id', 1)->update(['sales_ai_mode' => 'human', 'sales_ai_version' => 2]);

            return Http::response($this->response());
        }]);
        app(Runner::class)->process($id);
        $this->assertDatabaseHas('sales_ai_runs', ['id' => $id, 'status' => 'cancelled', 'charge_micros' => 0]);
        $this->assertSame(1, (int) DB::table('sales_ai_wallets')->value('balance_micros'));
        $this->assertSame(0, DB::table('sales_ai_entries')->count());
        $this->assertDatabaseHas('ai_balance_entries', ['run_id' => $id, 'amount_nanos' => -361_200]);
    }
}
