<?php

namespace Tests\Feature;

use App\Jobs\CustomerServiceReply;
use App\Jobs\SalesAiReply;
use App\Services\SalesAi\Runner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiNodeQueueTest extends TestCase
{
    private const SECRET = 'ai-node-test-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.node_queue_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'ai_node.enabled' => true, 'ai_node.secret' => self::SECRET,
            'ai_node.url' => 'https://socket.example.test/ai-jobs']);
        Cache::flush();
        DB::setDefaultConnection('node_queue_fixture');
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue');
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Http::preventStrayRequests();
    }

    private function enqueue(string $id = 'fixture', int $delay = 0): void
    {
        SalesAiReply::dispatch($id)->onConnection('sales-ai')->onQueue('sales-ai')->delay($delay);
    }

    public function test_wyne_wyne_jobs_wake_node_and_generate_in_the_real_worker(): void
    {
        Http::fake(['socket.example.test/*' => Http::response(['accepted' => true], 202)]);
        $runner = $this->mock(\App\Services\WyneWyneRunner::class);
        $runner->shouldReceive('process')->once()->with('legacy-fixture')->andReturn(true);
        $runner->shouldNotReceive('fail');
        \App\Jobs\WyneWyneReply::dispatch('legacy-fixture');
        Http::assertSent(fn ($request) => $request['queue'] === 'wyne-wyne');
        $this->sendCallback(queue: 'wyne-wyne')->assertOk()->assertJsonPath('retry_after', null);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function sendCallback(string $queue = 'sales-ai', ?string $nonce = null, string $purpose = 'process', ?int $time = null)
    {
        $body = json_encode(['queue' => $queue, 'request_id' => $nonce ?? (string) Str::uuid()]);
        $timestamp = (string) ($time ?? time());

        return $this->call('POST', '/integrations/ai-jobs/process', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AI_TIMESTAMP' => $timestamp,
            'HTTP_X_AI_SIGNATURE' => hash_hmac('sha256', $purpose."\n".$timestamp."\n".$body, self::SECRET),
        ], content: $body);
    }

    public function test_wyne_wyne_callback_shares_the_cron_overlap_lock(): void
    {
        $lock = fopen(storage_path('framework/cron-worker-wyne-wyne.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->mock(\App\Services\WyneWyneRunner::class)->shouldNotReceive('process');
            $this->sendCallback(queue: 'wyne-wyne')->assertOk()->assertJsonPath('retry_after', 2);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function test_committed_jobs_send_identifier_only_signed_wakeups_and_rollback_sends_nothing(): void
    {
        Http::fake(['socket.example.test/*' => Http::response(['accepted' => true], 202)]);
        DB::beginTransaction();
        $this->enqueue();
        Http::assertNothingSent();
        DB::commit();
        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-AI-Timestamp')[0];
            $this->assertEqualsCanonicalizing(['queue', 'request_id'], array_keys($request->data()));
            $this->assertSame('sales-ai', $request['queue']);
            $this->assertSame(hash_hmac('sha256', "wake\n".$timestamp."\n".$request->body(), self::SECRET), $request->header('X-AI-Signature')[0]);

            return true;
        });
        DB::beginTransaction();
        $this->enqueue('rolled-back');
        DB::rollBack();
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_signed_callback_consumes_real_queue_once_and_rejects_replays(): void
    {
        Http::fake(['socket.example.test/*' => Http::response(['accepted' => true], 202)]);
        $this->mock(Runner::class)->shouldReceive('process')->once()->with('fixture')->andReturn(true);
        $this->enqueue();
        $nonce = (string) Str::uuid();
        $this->sendCallback(nonce: $nonce)->assertOk()->assertJsonPath('retry_after', null);
        $this->sendCallback(nonce: $nonce)->assertStatus(409);
        $this->sendCallback()->assertOk()->assertJsonPath('retry_after', null);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_authentication_direction_expiry_and_queue_allowlist_are_enforced_before_processing(): void
    {
        $this->mock(Runner::class)->shouldNotReceive('process');
        $this->postJson('/integrations/ai-jobs/process', ['queue' => 'sales-ai'])->assertUnauthorized();
        $this->sendCallback(purpose: 'wake')->assertUnauthorized();
        $this->sendCallback(time: time() - 90)->assertUnauthorized();
        $this->sendCallback(queue: 'default')->assertUnprocessable();
        config(['ai_node.enabled' => false]);
        $this->sendCallback()->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_delayed_and_released_jobs_are_returned_to_node_without_running_them_early(): void
    {
        Http::fake(['socket.example.test/*' => Http::response(['accepted' => true], 202)]);
        $runner = $this->mock(Runner::class);
        $runner->shouldReceive('process')->once()->with('fixture')->andReturn(false);
        $this->enqueue(delay: 30);
        $response = $this->sendCallback()->assertOk();
        $this->assertGreaterThan(0, $response->json('retry_after'));
        DB::table('jobs')->update(['available_at' => time() - 1]);
        $response = $this->sendCallback()->assertOk();
        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNull(DB::table('jobs')->value('reserved_at'));
    }

    public function test_node_failure_keeps_the_job_for_cron_and_disabled_dispatch_does_not_call_node(): void
    {
        Http::fake(['socket.example.test/*' => Http::response([], 503)]);
        $this->enqueue();
        $this->assertSame(1, DB::table('jobs')->count());
        config(['ai_node.enabled' => false]);
        $this->enqueue('cron-only');
        Http::assertSentCount(1);
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_cron_overlap_lock_defers_node_processing(): void
    {
        $lock = fopen(storage_path('framework/cron-worker-sales-ai.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->mock(Runner::class)->shouldNotReceive('process');
            $this->sendCallback()->assertOk()->assertJsonPath('retry_after', 2);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_customer_service_queue_uses_the_same_wakeup_and_real_worker_path(): void
    {
        Http::fake(['socket.example.test/*' => Http::response(['accepted' => true], 202)]);
        $this->mock(\App\Services\TelegramSupport\Runner::class)->shouldReceive('process')
            ->once()->with('customer-fixture')->andReturn(0);
        CustomerServiceReply::dispatch('customer-fixture');
        Http::assertSent(fn ($request) => $request['queue'] === 'customer-service');
        $this->sendCallback(queue: 'customer-service')->assertOk()->assertJsonPath('retry_after', null);
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
