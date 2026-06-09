<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Release scheduled services the moment they come due. Requires the scheduler to be
// running: `php artisan schedule:work` in dev, or a once-a-minute cron in prod.
Schedule::command('services:dispatch-due')->everyMinute()->withoutOverlapping();
