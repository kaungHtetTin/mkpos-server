<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Shop orders have no automatic processing deadline.
        $this->scheduledCommand($schedule, 'shop-chat:expire-images')->everyMinute()->withoutOverlapping();
        $this->scheduledCommand($schedule, 'sales-ai:process --limit=10')->everyMinute()->withoutOverlapping(10);
        $this->scheduledCommand($schedule, 'wyne-wyne:recover --limit=50')->everyMinute()->withoutOverlapping();
        $this->scheduledCommand($schedule, 'customer-service:recover --limit=50')->everyMinute()->withoutOverlapping();
        $this->scheduledCommand($schedule, 'customer-service:prune')->daily()->withoutOverlapping();
        $this->scheduledCommand($schedule, 'sales-ai:prune-actions')->hourly()->withoutOverlapping();
        $this->scheduledCommand($schedule, 'shop:notify-restocks')->everyMinute()->withoutOverlapping();
        $this->scheduledCommand($schedule, 'mcp:support-prune')->hourly()->withoutOverlapping();
        $schedule->call(fn () => \Illuminate\Support\Facades\DB::table('password_reset_otps')->where('expires_at', '<', now()->subDay())->delete())->daily()->name('password-reset:prune')->withoutOverlapping();
    }

    protected function canStartScheduledProcesses(): bool
    {
        return function_exists('proc_open');
    }

    protected function scheduledCommand(Schedule $schedule, string $command): Event
    {
        if ($this->canStartScheduledProcesses()) {
            return $schedule->command($command);
        }

        // Some shared hosts disable proc_open even for cron's PHP CLI.
        // A named callback keeps scheduling/overlap locks without spawning PHP.
        return $schedule->call(function () use ($command) {
            $exitCode = app(ConsoleKernelContract::class)->call($command);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Scheduled command failed: '.$command.' (exit '.$exitCode.').');
            }
        })->name($command);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
