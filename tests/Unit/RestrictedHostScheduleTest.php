<?php

namespace Tests\Unit;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Tests\TestCase;

class RestrictedHostScheduleTest extends TestCase
{
    public function test_runtime_function_availability_selects_the_schedule_type(): void
    {
        $kernel = new class($this->app, $this->app['events']) extends Kernel
        {
            public function registerSchedule(Schedule $schedule): void
            {
                $this->schedule($schedule);
            }
        };
        $schedule = new Schedule;
        $kernel->registerSchedule($schedule);

        $this->assertSame(! function_exists('proc_open'), $schedule->events()[0] instanceof CallbackEvent);
    }

    private function events(): array
    {
        config(['cache.default' => 'array']);
        $kernel = new class($this->app, $this->app['events']) extends Kernel
        {
            protected function canStartScheduledProcesses(): bool
            {
                return false;
            }

            public function registerSchedule(Schedule $schedule): void
            {
                $this->schedule($schedule);
            }
        };
        $schedule = new Schedule;
        $kernel->registerSchedule($schedule);

        return $schedule->events();
    }

    public function test_restricted_host_uses_named_callbacks_with_existing_frequencies_and_locks(): void
    {
        $events = $this->events();
        $this->assertCount(9, $events);
        foreach ($events as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertNotEmpty($event->description);
        }
        $sales = collect($events)->firstWhere('description', 'sales-ai:process --limit=10');
        $this->assertSame('* * * * *', $sales->expression);
        $this->assertSame(10, $sales->expiresAt);
        $this->assertCount(9, array_unique(array_map(fn ($event) => $event->mutexName(), $events)));
    }

    public function test_recovery_runs_in_process_and_nonzero_exit_fails_the_scheduled_event(): void
    {
        $sales = collect($this->events())->firstWhere('description', 'sales-ai:process --limit=10');
        $this->mock(ConsoleKernelContract::class)->shouldReceive('call')->once()
            ->with('sales-ai:process --limit=10')->andReturn(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sales-ai:process --limit=10 (exit 1)');
        $sales->run($this->app);
    }

    public function test_successful_recovery_completes_without_a_subprocess(): void
    {
        $sales = collect($this->events())->firstWhere('description', 'sales-ai:process --limit=10');
        $this->mock(ConsoleKernelContract::class)->shouldReceive('call')->once()
            ->with('sales-ai:process --limit=10')->andReturn(0);

        $sales->run($this->app);
        $this->assertSame(0, $sales->exitCode);
    }
}
