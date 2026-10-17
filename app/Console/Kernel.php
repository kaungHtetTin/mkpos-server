<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
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
        $schedule->command('shop-chat:expire-images')->everyMinute()->withoutOverlapping();
        $schedule->command('shop:notify-restocks')->everyMinute()->withoutOverlapping();
        $schedule->call(fn () => \Illuminate\Support\Facades\DB::table('password_reset_otps')->where('expires_at', '<', now()->subDay())->delete())->daily()->name('password-reset:prune')->withoutOverlapping();
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
