<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\WorkerHeartbeat;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class WorkerHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram_support.enabled' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        config(['database.connections.worker_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'queue.default' => 'sync', 'queue.connections.sales-ai.connection' => 'worker_fixture', 'queue.connections.wyne-wyne.connection' => 'worker_fixture',
            'worker_health.payment_validation_required' => true]);
        DB::setDefaultConnection('worker_fixture');
        (require database_path('migrations/2026_10_26_000003_create_worker_heartbeats.php'))->up();
        Schema::create('jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('queue');
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('reserved_at')->nullable();
        });
        Schema::create('sales_ai_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('status');
            $table->timestamp('created_at');
            $table->timestamp('started_at')->nullable();
        });
        Schema::create('system_agent_reviews', function (Blueprint $table) {
            $table->increments('id');
            $table->string('status');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    private function commandEvent(string $name, bool $start, int $exitCode = 0): void
    {
        $input = new ArrayInput(['command' => $name]);
        $output = new NullOutput;
        Event::dispatch($start ? new CommandStarting($name, $input, $output) : new CommandFinished($name, $input, $output, $exitCode));
    }

    private function healthy(): void
    {
        Event::dispatch(new Looping('sales-ai', 'sales-ai'));
        Event::dispatch(new Looping('wyne-wyne', 'wyne-wyne'));
        foreach (['schedule:run', 'system-agent:validate-payments'] as $command) {
            $this->commandEvent($command, true);
            $this->commandEvent($command, false);
        }
    }

    public function test_http_checks_report_missing_workers_without_starting_paid_work_or_writing_heartbeats(): void
    {
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('ok', false)
            ->assertJsonPath('workers.sales_ai.status', 'missing')->assertJsonPath('workers.scheduler.status', 'missing')
            ->assertJsonPath('workers.payment_validation.status', 'missing')->assertJsonPath('workers.default_queue.status', 'not_required')
            ->assertJsonPath('wyne_wyne.worker_required', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(0, DB::table('worker_heartbeats')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_opening_api_routes_in_a_browser_returns_authentication_errors_without_a_login_redirect(): void
    {
        foreach (['/api/office/health/workers', '/api/auth/me'] as $path) {
            $this->get($path, ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'])
                ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.')
                ->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
        }
        $this->assertSame(0, DB::table('worker_heartbeats')->count());
    }

    public function test_browser_can_read_public_health_but_business_login_cannot_read_office_diagnostics(): void
    {
        $this->healthy();
        $this->get('/api/health/workers', ['Accept' => 'text/html'])->assertOk()->assertJsonPath('ok', true);
        $user = new User(['name' => 'Business owner', 'role' => 'owner']);
        $user->id = 1;
        $this->actingAs($user, 'web')->get('/api/office/health/workers', ['Accept' => 'text/html'])
            ->assertUnauthorized()->assertJsonMissingPath('workers.sales_ai.queue');
        $admin = new PlatformAdmin(['name' => 'Office admin', 'is_active' => true, 'role' => 'admin']);
        $admin->id = 1;
        $this->actingAs($admin, 'office')->get('/api/office/health/workers', ['Accept' => 'text/html'])
            ->assertOk()->assertJsonPath('workers.sales_ai.queue.ready', 0);
    }

    public function test_real_worker_events_make_the_endpoint_healthy_and_details_require_office_authentication(): void
    {
        $this->healthy();
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('workers.sales_ai.status', 'healthy')->assertJsonMissingPath('workers.sales_ai.queue');
        $this->getJson('/api/office/health/workers')->assertUnauthorized();
        $admin = new PlatformAdmin(['name' => 'Health reader', 'is_active' => true, 'role' => 'admin']);
        $admin->id = 1;
        $this->actingAs($admin, 'office')->getJson('/api/office/health/workers')->assertOk()
            ->assertJsonPath('workers.sales_ai.active_instances', 1)->assertJsonPath('workers.sales_ai.queue.ready', 0);
    }

    public function test_stale_and_stopped_workers_fail_even_when_the_queue_is_empty(): void
    {
        $this->healthy();
        DB::table('worker_heartbeats')->where('worker', 'sales_ai')->update(['last_seen_at' => now('UTC')->subSeconds(46)]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.sales_ai.status', 'stale');
        Event::dispatch(new WorkerStopping);
        $this->assertSame('stopped', DB::table('worker_heartbeats')->where('worker', 'sales_ai')->value('state'));
    }

    public function test_busy_worker_has_a_bounded_grace_period_and_successful_job_refreshes_it(): void
    {
        $this->healthy();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->andReturn('sales-ai');
        $job->shouldReceive('timeout')->andReturn(360);
        $job->shouldReceive('payload')->andReturn([]);
        Event::dispatch(new JobProcessing('sales-ai', $job));
        DB::table('worker_heartbeats')->where('worker', 'sales_ai')->update(['last_seen_at' => now('UTC')->subSeconds(90)]);
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('workers.sales_ai.status', 'healthy');
        DB::table('worker_heartbeats')->where('worker', 'sales_ai')->update(['busy_until' => now('UTC')->subSecond()]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.sales_ai.status', 'stale');
        Event::dispatch(new JobProcessed('sales-ai', $job));
        $this->getJson('/api/health/workers')->assertOk();
        $this->assertNotNull(DB::table('worker_heartbeats')->where('worker', 'sales_ai')->value('last_success_at'));
    }

    public function test_another_live_worker_keeps_the_group_healthy_when_one_instance_stops(): void
    {
        $this->healthy();
        $other = (array) DB::table('worker_heartbeats')->where('worker', 'sales_ai')->first();
        $other['id'] = str_repeat('a', 64);
        DB::table('worker_heartbeats')->insert($other);
        Event::dispatch(new WorkerStopping);
        app(WorkerHeartbeat::class)->queue('wyne-wyne', 'wyne-wyne');
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('workers.sales_ai.status', 'healthy');
    }

    public function test_old_ready_jobs_fail_health_while_delayed_jobs_do_not(): void
    {
        $this->healthy();
        $id = DB::table('jobs')->insertGetId(['queue' => 'sales-ai', 'available_at' => time() + 3600]);
        $this->getJson('/api/health/workers')->assertOk();
        DB::table('jobs')->where('id', $id)->update(['available_at' => time() - 601]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.sales_ai.status', 'backlogged');
    }

    public function test_lost_dispatches_and_stuck_provider_runs_are_detected(): void
    {
        $this->healthy();
        DB::table('sales_ai_runs')->insert(['status' => 'pending', 'created_at' => now('UTC')->subMinutes(11)]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.sales_ai.status', 'backlogged');
        DB::table('sales_ai_runs')->update(['status' => 'processing', 'started_at' => now('UTC')->subMinutes(11)]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.sales_ai.status', 'backlogged');
    }

    public function test_failed_scheduler_and_payment_worker_commands_are_unhealthy(): void
    {
        $this->healthy();
        app(WorkerHeartbeat::class)->schedulerFailed();
        $this->commandEvent('schedule:run', false);
        $this->commandEvent('system-agent:validate-payments', false, 1);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.scheduler.status', 'failed')
            ->assertJsonPath('workers.payment_validation.status', 'failed');
    }

    public function test_async_default_queue_requires_its_own_worker_but_unrelated_connections_do_not_count(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'worker_fixture']);
        $this->healthy();
        Event::dispatch(new Looping('redis', 'default'));
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.default_queue.status', 'missing');
        Event::dispatch(new Looping('database', 'other,default'));
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('workers.default_queue.status', 'healthy');
    }

    public function test_optional_payment_worker_does_not_fail_overall_health_and_missing_monitor_never_claims_health(): void
    {
        $this->healthy();
        config(['worker_health.payment_validation_required' => false]);
        DB::table('worker_heartbeats')->where('worker', 'payment_validation')->delete();
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('workers.payment_validation.status', 'not_required');
        Schema::drop('worker_heartbeats');
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('status', 'monitor_not_ready');
    }

    public function test_telegram_customer_service_is_required_only_when_enabled_and_needs_its_own_worker(): void
    {
        $this->healthy();
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('workers.customer_service.status', 'not_required');
        (require database_path('migrations/2026_10_26_000005_create_customer_service_agent.php'))->up();
        config(['telegram_support.enabled' => true, 'telegram_support.bot_token' => '123456:fixture_token_12345678901234567890', 'telegram_support.webhook_secret' => str_repeat('s', 40)]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.customer_service.status', 'missing');
        Event::dispatch(new Looping('customer-service', 'customer-service'));
        $this->getJson('/api/health/workers')->assertOk()->assertJsonPath('workers.customer_service.status', 'healthy');
        config(['telegram_support.bot_token' => '']);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.customer_service.status', 'unconfigured');
    }

    public function test_database_monitor_errors_return_503_without_raw_exception_details(): void
    {
        $this->healthy();
        Schema::drop('jobs');
        $response = $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('status', 'monitor_unavailable');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_legacy_queued_payments_require_a_worker_even_when_it_is_normally_optional(): void
    {
        $this->healthy();
        config(['worker_health.payment_validation_required' => false]);
        DB::table('worker_heartbeats')->where('worker', 'payment_validation')->delete();
        DB::table('system_agent_reviews')->insert(['status' => 'queued', 'created_at' => now('UTC'),
            'updated_at' => now('UTC'), 'available_at' => now('UTC')]);
        $this->getJson('/api/health/workers')->assertStatus(503)->assertJsonPath('workers.payment_validation.required', true)
            ->assertJsonPath('workers.payment_validation.status', 'missing');
    }
}
