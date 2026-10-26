<?php

namespace App\Jobs;

use App\Services\SalesAi\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SalesAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 60;
    public int $maxExceptions = 1;
    public int $timeout = 360;
    public bool $failOnTimeout = true;
    public function __construct(public string $runId) {}
    public function handle(Runner $runner): void { if (! $runner->process($this->runId)) $this->release(5); }
    public function failed(?\Throwable $error): void { app(Runner::class)->fail($this->runId, 'worker_interrupted'); }
}
