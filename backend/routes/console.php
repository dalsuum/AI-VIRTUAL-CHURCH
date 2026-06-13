<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Release scheduled services the moment they come due. Requires the scheduler to be
// running: `php artisan schedule:work` in dev, or a once-a-minute cron in prod.
Schedule::command('services:dispatch-due')->everyMinute();

// Train custom Voice Studio MMS/VITS voices only in the low-load overnight window.
// The command itself re-checks the configured time window and load average before
// it starts any heavy work, so this scheduler line is a coarse first gate.
Schedule::command('voice-studio:train-due')
    ->everyThirtyMinutes()
    ->between('2:00', '6:00')
    ->runInBackground()
    ->withoutOverlapping(300);
