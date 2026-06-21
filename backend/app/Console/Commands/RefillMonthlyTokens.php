<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Console\Command;

/**
 * Monthly token refill. Resets each registered (non-guest) user's wallet to their
 * plan's allowance once per calendar month. Idempotent within a month: a user whose
 * tokens_refilled_at is already in the current month is skipped, so re-running the
 * command (or running it daily as a safety net) never double-grants.
 */
class RefillMonthlyTokens extends Command
{
    protected $signature = 'tokens:refill-monthly';
    protected $description = 'Reset member/premium token wallets to their monthly allowance';

    public function handle(TokenService $tokens): int
    {
        $count = 0;

        User::query()
            ->where('email', 'not like', '%@guest.local')
            ->where(function ($q) {
                $q->whereNull('tokens_refilled_at')
                  ->orWhere('tokens_refilled_at', '<', now()->startOfMonth());
            })
            ->chunkById(200, function ($users) use ($tokens, &$count) {
                foreach ($users as $user) {
                    $tokens->refillMonthly($user);
                    $count++;
                }
            });

        $this->info("Refilled {$count} wallet(s).");

        return self::SUCCESS;
    }
}
