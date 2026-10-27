<?php

namespace App\Jobs;

use App\Services\WyneWyneRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WyneWyneReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 360;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onConnection('wyne-wyne')->onQueue('wyne-wyne');
    }

    public function handle(WyneWyneRunner $runner): void
    {
        // Compatibility for jobs serialized before HTTP delivery was introduced.
        // Never replay an old provider request in a worker; let the user retry.
        $runner->fail($this->runId, 'http_retry_required');
    }

    public function failed(?\Throwable $error): void
    {
        app(WyneWyneRunner::class)->fail($this->runId, 'worker_interrupted');
    }
}
