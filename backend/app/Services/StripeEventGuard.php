<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transactional, DB-enforced "process this Stripe event exactly once".
 *
 * Stripe redelivers events and two deliveries can interleave. We open a transaction,
 * INSERT the event id (UNIQUE) first, then run the handler in the same transaction:
 *
 *   • duplicate delivery → the insert hits the unique constraint → we skip, no work done;
 *   • handler throws       → the whole transaction rolls back, INCLUDING the marker, so
 *                            Stripe's retry can legitimately reprocess it;
 *   • handler succeeds     → marker + side effects commit atomically.
 *
 * This closes the partial-state window that state-guards alone leave open.
 */
class StripeEventGuard
{
    /** @return bool true if the handler ran, false if the event was already processed. */
    public function once(string $eventId, string $type, callable $handler): bool
    {
        try {
            return DB::transaction(function () use ($eventId, $type, $handler) {
                // Insert-before-process. A concurrent/duplicate delivery racing the same
                // id will block here and then fail the unique insert below.
                DB::table('stripe_webhook_events')->insert([
                    'event_id'     => $eventId,
                    'type'         => $type,
                    'processed_at' => now(),
                ]);

                $handler();

                return true;
            });
        } catch (QueryException $e) {
            // 23000 = integrity constraint violation (duplicate event_id) → already done.
            if (($e->errorInfo[0] ?? null) === '23000') {
                Log::info('Stripe event already processed; skipping', ['event' => $eventId, 'type' => $type]);

                return false;
            }
            throw $e;
        }
    }
}
