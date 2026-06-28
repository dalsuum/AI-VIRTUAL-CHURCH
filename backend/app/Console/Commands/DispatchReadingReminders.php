<?php

namespace App\Console\Commands;

use App\Domains\Bible\Services\ReminderService;
use Illuminate\Console\Command;

/**
 * Sends daily-reading reminders that came due in the last few minutes, in each user's
 * own timezone. Idempotent per (user, slot, local-date), so the five-minute cadence
 * never double-sends and a missed tick self-heals on the next run.
 *
 *   php artisan reading:remind
 */
class DispatchReadingReminders extends Command
{
    protected $signature = 'reading:remind';

    protected $description = 'Deliver due daily Bible-reading reminders';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->dispatchDue();
        $this->info("Sent {$sent} reading reminder(s).");

        return self::SUCCESS;
    }
}
