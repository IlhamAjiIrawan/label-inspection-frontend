<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();

        // Hybrid PUSH/PULL — reconcile OCR yang missed webhook tiap 5 menit
        if (config('services.ocr.reconcile_enabled', true)) {
            $schedule->command('ocr:reconcile --limit=20')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/ocr-reconcile.log'));
        }

        // Library FastAPI stateless/MySQL (§9 plan) — DB ping tiap 5 menit,
        // alert (error log) jika gagal 2x beruntun. Tanpa overlapping.
        $schedule->command('ref:library-health')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/ref-library-health.log'));
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
