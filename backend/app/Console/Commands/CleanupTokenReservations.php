<?php

namespace App\Console\Commands;

use App\Models\TokenReservation;
use App\Services\TokenService;
use Illuminate\Console\Command;

/**
 * Roll back token reservations left pending past their TTL — the case where a worker
 * died after reserve() but before commit()/rollback(). Without this, those tokens stay
 * debited forever. Runs frequently (hourly) since a stranded reservation is money the
 * user paid for but didn't get.
 */
class CleanupTokenReservations extends Command
{
    protected $signature = 'reservations:cleanup';
    protected $description = 'Refund token reservations stuck pending past their expiry';

    public function handle(TokenService $tokens): int
    {
        $count = 0;

        TokenReservation::where('status', TokenReservation::PENDING)
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($reservations) use ($tokens, &$count) {
                foreach ($reservations as $reservation) {
                    $tokens->rollback($reservation);
                    $count++;
                }
            });

        $this->info("Rolled back {$count} expired reservation(s).");

        return self::SUCCESS;
    }
}
