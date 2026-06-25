<?php

namespace App\Console\Commands;

use App\Models\GuestTracking;
use Illuminate\Console\Command;

/**
 * Prune stale guest-tracking rows. The "one free use" quota only needs to persist long
 * enough to deter abuse; after the retention window a returning anonymous visitor is
 * effectively a new guest. Also bounds table growth. Window is configurable.
 */
class CleanupGuestTracking extends Command
{
    protected $signature = 'guests:cleanup {--days=}';
    protected $description = 'Delete guest-tracking rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('tokens.guest_tracking_retention_days', 90));

        $deleted = GuestTracking::where('updated_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} stale guest-tracking row(s).");

        return self::SUCCESS;
    }
}
