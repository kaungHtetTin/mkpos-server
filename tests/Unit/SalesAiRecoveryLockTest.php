<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesAiRecoveryLockTest extends TestCase
{
    public function test_recovery_skips_the_dedicated_worker_lock_and_runs_after_it_is_released(): void
    {
        $lock = fopen(storage_path('framework/cron-worker-sales-ai.lock'), 'c');
        $this->assertNotFalse($lock);
        flock($lock, LOCK_EX);
        try {
            $this->artisan('sales-ai:process', ['--limit' => 1])
                ->expectsOutput('Sales AI worker is running; recovery skipped.')->assertSuccessful();
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
        Schema::shouldReceive('hasTable')->once()->with('sales_ai_runs')->andReturn(false);
        $this->artisan('sales-ai:process', ['--limit' => 1])->assertSuccessful();
        $probe = fopen(storage_path('framework/cron-worker-sales-ai.lock'), 'c');
        $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
        flock($probe, LOCK_UN); fclose($probe);
    }
}
