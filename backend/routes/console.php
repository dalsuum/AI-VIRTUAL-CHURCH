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

// Evaluate the special-Sunday window once a day (early, before traffic) so the
// current-observance cache is warm and the active observance is logged. The
// resolver is also consulted live at dispatch/request time, so this is a warmer,
// not the critical path. See App\Services\SpecialSundayResolver for window math.
Schedule::command('special-sunday:evaluate')->dailyAt('00:05');

// Bound disk use for the two public-upload media features (Special Day MV +
// Live Sticker): drop finished outputs past their retention window and abandoned
// uploads after 1h. The render path also sweeps opportunistically; this is the
// guaranteed daily backstop so a public endpoint can't fill the filesystem.
Schedule::command('media:prune')->dailyAt('03:30');

// Train custom Voice Studio MMS/VITS voices only in the low-load overnight window.
// The command itself re-checks the configured time window and load average before
// it starts any heavy work, so this scheduler line is a coarse first gate.
Schedule::command('voice-studio:train-due')
    ->everyThirtyMinutes()
    ->between('2:00', '6:00')
    ->runInBackground()
    ->withoutOverlapping(300);

// Subscription / token economy maintenance.
//   - Monthly token refill: run daily (idempotent within a month; only touches wallets
//     not yet refilled this month, so a missed day self-heals).
//   - Subscription expiry: daily backstop for missed Stripe deletion webhooks.
//   - Guest-tracking prune: daily, bounds table growth.
//   - Reservation cleanup: hourly, refunds tokens stranded by a crashed worker.
Schedule::command('tokens:refill-monthly')->dailyAt('00:10');
Schedule::command('subscriptions:expire')->dailyAt('00:15');
Schedule::command('guests:cleanup')->dailyAt('03:45');
Schedule::command('reservations:cleanup')->hourly();
