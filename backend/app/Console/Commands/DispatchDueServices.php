<?php

namespace App\Console\Commands;

use App\Jobs\DispatchServiceJob;
use App\Models\ServiceSession;
use App\Notifications\ServiceReminderNotification;
use Illuminate\Console\Command;

/**
 * Releases services whose scheduled time has arrived. Run every minute by the
 * scheduler (see routes/console.php). Each due session is flipped to 'active' and
 * handed to the same DispatchServiceJob a walk-up intake uses.
 */
class DispatchDueServices extends Command
{
    protected $signature = 'services:dispatch-due';
    protected $description = 'Dispatch any scheduled services whose time has arrived';

    public function handle(): int
    {
        $due = ServiceSession::with('user')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $session) {
            $session->update(['status' => 'active']);
            DispatchServiceJob::dispatch($session->id);

            // Remind the worshipper their reserved time has come. Guests are given a
            // synthetic @guest.local address we can't deliver to, so only registered
            // users are mailed. Selecting 'scheduled' and flipping to 'active' above
            // guarantees each session is released — and reminded — exactly once.
            $user = $session->user;
            if ($user && ! str_ends_with($user->email, '@guest.local')) {
                $user->notify(new ServiceReminderNotification($session));
            }

            $this->info("Dispatched scheduled service {$session->id}");
        }

        $this->info("Released {$due->count()} scheduled service(s).");

        return self::SUCCESS;
    }
}
