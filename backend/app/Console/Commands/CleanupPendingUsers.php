<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Remove never-activated accounts. A user who registers but never clicks the activation
 * link is left as a PENDING row holding an email address; once the activation window has
 * passed there is no way to activate it, so we delete it (freeing the email for re-use).
 *
 * Only ever touches status=pending rows older than the verification window — active
 * users are never deleted. Cascade FKs on token_ledger / token_reservations remove the
 * user's temporary records automatically. Runs hourly from the scheduler.
 */
class CleanupPendingUsers extends Command
{
    protected $signature = 'users:cleanup-pending';
    protected $description = 'Delete pending (never-activated) users past the verification window';

    public function handle(): int
    {
        $hours  = (int) config('account.verification_expires_hours', 24);
        $cutoff = now()->subHours($hours);

        $deleted = 0;

        User::query()
            ->where('status', User::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($users) use (&$deleted) {
                foreach ($users as $user) {
                    $user->delete(); // cascades to token_ledger / token_reservations
                    $deleted++;
                }
            });

        if ($deleted > 0) {
            Log::info('activation.cleanup', ['deleted' => $deleted]);
        }
        $this->info("Deleted {$deleted} expired pending user(s).");

        return self::SUCCESS;
    }
}
