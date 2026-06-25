<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Enums\SubscriptionPlan;
use App\Exceptions\InsufficientTokensException;
use App\Models\TokenLedger;
use App\Models\TokenReservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The token wallet. The authoritative balance is users.token_balance; every mutation
 * is recorded in token_ledger inside the same transaction that moves it, and the user
 * row is locked (lockForUpdate) so concurrent requests can never double-spend.
 *
 * AI calls use the two-phase API: reserve() before the (fallible) upstream request,
 * then commit() on success or rollback() on failure — so a timeout never charges a user.
 * spend() is the single-phase convenience for actions that can't fail mid-charge.
 */
class TokenService
{
    /** Token price of one action for $service. */
    public function cost(string $service): int
    {
        $costs = (array) config('tokens.costs', []);

        return (int) ($costs[$service] ?? $costs['default'] ?? 1);
    }

    /**
     * Phase 1: debit the wallet and open a pending reservation. The caller runs the AI
     * request, then commit()s or rollback()s. Throws if the balance is insufficient.
     */
    public function reserve(User $user, string $service, ?string $reference = null, ?int $amount = null): TokenReservation
    {
        $cost = $amount ?? $this->cost($service);

        return DB::transaction(function () use ($user, $service, $reference, $cost) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->token_balance < $cost) {
                throw new InsufficientTokensException($cost, (int) $locked->token_balance);
            }

            $locked->decrement('token_balance', $cost);
            $this->writeLedger($locked, -$cost, LedgerType::RESERVATION, $reference);

            $ttl = (int) config('tokens.reservation_ttl_minutes', 30);

            return TokenReservation::create([
                'user_id'    => $locked->id,
                'amount'     => $cost,
                'service'    => $service,
                'status'     => TokenReservation::PENDING,
                'reference'  => $reference,
                'expires_at' => Carbon::now()->addMinutes($ttl),
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /** Phase 2a: finalise a reservation. The debit at reserve() time stands. */
    public function commit(TokenReservation $reservation): void
    {
        if ($reservation->status !== TokenReservation::PENDING) {
            return; // idempotent — already resolved
        }
        $reservation->update([
            'status'      => TokenReservation::COMMITTED,
            'resolved_at' => Carbon::now(),
        ]);
    }

    /** Phase 2b: refund a reservation (upstream failed). Credits the wallet back. */
    public function rollback(TokenReservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $fresh = TokenReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== TokenReservation::PENDING) {
                return; // idempotent
            }

            $locked = User::whereKey($fresh->user_id)->lockForUpdate()->firstOrFail();
            $locked->increment('token_balance', $fresh->amount);
            $this->writeLedger($locked, $fresh->amount, LedgerType::ROLLBACK, $fresh->reference);

            $fresh->update([
                'status'      => TokenReservation::ROLLED_BACK,
                'resolved_at' => Carbon::now(),
            ]);
        });
    }

    /** Single-phase debit for actions that cannot fail mid-charge. Throws if short. */
    public function spend(User $user, string $service, ?string $reference = null, ?int $amount = null): void
    {
        $cost = $amount ?? $this->cost($service);

        DB::transaction(function () use ($user, $reference, $cost) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->token_balance < $cost) {
                throw new InsufficientTokensException($cost, (int) $locked->token_balance);
            }
            $locked->decrement('token_balance', $cost);
            $this->writeLedger($locked, -$cost, LedgerType::SPEND, $reference);
        });
    }

    /** Credit tokens (signup bonus, admin adjustment, purchase). */
    public function grant(User $user, int $amount, LedgerType $type = LedgerType::GRANT, ?string $reference = null): void
    {
        if ($amount <= 0) {
            return;
        }
        DB::transaction(function () use ($user, $amount, $type, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->increment('token_balance', $amount);
            $this->writeLedger($locked, $amount, $type, $reference);
        });
    }

    /**
     * Monthly refill: reset the wallet to the plan's allowance (allocations don't stack)
     * and stamp the allowance + refill time for the dashboard. No-op for guests.
     */
    public function refillMonthly(User $user): void
    {
        $plan = $user->plan();
        if ($plan === SubscriptionPlan::GUEST) {
            return;
        }
        $allowance = PlanService::monthlyAllowance($plan);

        DB::transaction(function () use ($user, $allowance) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $delta  = $allowance - (int) $locked->token_balance;

            $locked->update([
                'token_balance'      => $allowance,
                'monthly_allowance'  => $allowance,
                'tokens_refilled_at' => Carbon::now(),
            ]);

            if ($delta !== 0) {
                $this->writeLedger($locked->refresh(), $delta, LedgerType::REFILL, 'monthly');
            }
        });
    }

    /** Append a ledger row. Assumes $user already reflects the post-mutation balance. */
    private function writeLedger(User $user, int $amount, LedgerType $type, ?string $reference): void
    {
        TokenLedger::create([
            'user_id'       => $user->id,
            'amount'        => $amount,
            'balance_after' => (int) $user->token_balance,
            'type'          => $type,
            'reference'     => $reference,
            'created_at'    => Carbon::now(),
        ]);
    }
}
