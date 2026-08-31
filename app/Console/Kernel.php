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
        // Two daily jobs. Both keep information in front of a student correct
        // rather than nagging them: one warns about a deadline on something they
        // are already tracking, the other retires listings whose date has passed.
        $schedule->command('scholarzim:deadline-reminders')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('scholarzim:archive-expired-opportunities')
            ->dailyAt('00:30')
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
