<?php

namespace Tests\Feature;

use App\Jobs\SalesAiReply;
use App\Services\SalesAi\Runner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesAiQueueTest extends TestCase
{
    public function test_dedicated_database_queue_serializes_and_executes_the_job_once(): void
    {
        config(['database.connections.sales_queue_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('sales_queue_fixture');
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('queue')->index(); $table->longText('payload');
            $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
        });
        $runner = $this->mock(Runner::class);
        $runner->shouldReceive('process')->once()->with('queue-fixture')->andReturn(true);
        SalesAiReply::dispatch('queue-fixture')->onConnection('sales-ai')->onQueue('sales-ai');
        $queue = Queue::connection('sales-ai');
        $this->assertSame(1, $queue->size('sales-ai'));
        $job = $queue->pop('sales-ai');
        $this->assertNotNull($job);
        $job->fire();
        $this->assertSame(0, $queue->size('sales-ai'));
        $this->assertNull($queue->pop('sales-ai'));
    }
}
