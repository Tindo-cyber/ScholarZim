<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Daily reminder jobs, carried over from the Spring @Scheduled beans.
        $schedule->command('scholarzim:deadline-reminders')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('scholarzim:profile-reminders')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
