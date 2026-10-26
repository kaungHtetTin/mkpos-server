<?php

namespace App\Providers;

use App\Services\WorkerHeartbeat;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class WorkerHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeat::class);
    }

    public function boot(): void
    {
        Event::listen(Looping::class, fn ($event) => app(WorkerHeartbeat::class)->queue($event->connectionName, (string) $event->queue, 'loop'));
        Event::listen(JobProcessing::class, fn ($event) => app(WorkerHeartbeat::class)->queue($event->connectionName, $event->job->getQueue(), 'busy', $event->job->timeout()));
        Event::listen(JobProcessed::class, fn ($event) => app(WorkerHeartbeat::class)->queue($event->connectionName, $event->job->getQueue()));
        Event::listen(JobExceptionOccurred::class, fn ($event) => app(WorkerHeartbeat::class)->queue($event->connectionName, $event->job->getQueue(), failed: true));
        Event::listen(WorkerStopping::class, fn () => app(WorkerHeartbeat::class)->stopping());
        Event::listen(ScheduledTaskFailed::class, fn () => app(WorkerHeartbeat::class)->schedulerFailed());
        Event::listen(CommandStarting::class, function ($event) {
            $limit = $event->input->hasParameterOption('--limit') ? (int) $event->input->getParameterOption('--limit') : 5;
            app(WorkerHeartbeat::class)->command($event->command, true, limit: $limit);
        });
        Event::listen(CommandFinished::class, fn ($event) => app(WorkerHeartbeat::class)->command($event->command, false, $event->exitCode));
    }
}
