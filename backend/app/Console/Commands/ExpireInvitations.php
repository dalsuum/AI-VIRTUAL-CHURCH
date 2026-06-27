<?php

namespace App\Console\Commands;

use App\Domains\Invitations\Services\InvitationService;
use Illuminate\Console\Command;

/**
 * Sweeps pending invitations past their expires_at to EXPIRED through the one
 * transition path (so InvitationExpired fires and audit stays consistent). Idempotent:
 * only still-pending, past-due rows are touched, so a missed run self-heals next tick.
 *
 *   php artisan invitations:expire
 */
class ExpireInvitations extends Command
{
    protected $signature = 'invitations:expire {--limit=500 : Max invitations to expire per run}';

    protected $description = 'Expire pending invitations past their expiry time';

    public function handle(InvitationService $invitations): int
    {
        $count = $invitations->expireDue((int) $this->option('limit'));
        $this->info("Expired {$count} invitation(s).");

        return self::SUCCESS;
    }
}
