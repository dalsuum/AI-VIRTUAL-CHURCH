<?php

namespace App\Services;

use App\Models\FinancialLedger;
use App\Models\ServiceSession;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * The offering segment. Money never touches our server: we create a Stripe
 * PaymentIntent and hand its client_secret to the browser, which confirms the
 * card directly with Stripe. We only record the result — and only once Stripe
 * tells us (via webhook) the charge actually succeeded.
 *
 * The PaymentIntent id doubles as the ledger's idempotency key, so a webhook
 * Stripe redelivers (it can, and does) never double-books an offering.
 */
class OfferingService
{
    private const ALLOCATIONS = ['operations', 'charity', 'missions'];

    /** Stripe works in the currency's minor unit (cents); guard a sane floor. */
    private const MIN_MINOR = 100;  // 1.00
    private const MAX_MINOR = 10_000_00;

    public function __construct(private StripeClient $stripe) {}

    /**
     * Create a PaymentIntent for an offering and return what the browser needs
     * to confirm it. Nothing is written to the ledger here — see record().
     *
     * @param  int  $amountMinor  amount in the currency's minor unit (cents)
     * @return array{client_secret: string, payment_intent: string, amount: int, currency: string}
     */
    public function createIntent(ServiceSession $session, int $amountMinor, string $allocation): array
    {
        if (! in_array($allocation, self::ALLOCATIONS, true)) {
            abort(422, 'Unknown allocation.');
        }
        if ($amountMinor < self::MIN_MINOR || $amountMinor > self::MAX_MINOR) {
            abort(422, 'Offering amount is outside the allowed range.');
        }

        $currency = strtolower(config('services.stripe.currency', 'usd'));

        $intent = $this->stripe->paymentIntents->create([
            'amount'                    => $amountMinor,
            'currency'                  => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            // Carried back to us on the webhook so the ledger row is fully attributed
            // without trusting anything the client re-sends.
            'metadata' => [
                'session_id'   => (string) $session->id,
                'user_id'      => (string) $session->user_id,
                'allocation'   => $allocation,
            ],
        ]);

        return [
            'client_secret'  => $intent->client_secret,
            'payment_intent' => $intent->id,
            'amount'         => $amountMinor,
            'currency'       => strtoupper($currency),
        ];
    }

    /**
     * Record a succeeded PaymentIntent in the ledger. Idempotent on the intent id,
     * so redelivered webhooks are no-ops.
     *
     * @return FinancialLedger|null  the row (created or pre-existing), or null if the
     *                               intent carried no allocation we trust.
     */
    public function record(PaymentIntent $intent): ?FinancialLedger
    {
        $allocation = $intent->metadata['allocation'] ?? null;
        if (! in_array($allocation, self::ALLOCATIONS, true)) {
            return null;
        }

        return FinancialLedger::firstOrCreate(
            ['transaction_hash' => $intent->id],
            [
                'user_id'         => $intent->metadata['user_id'] ?? null,
                'session_id'      => $intent->metadata['session_id'] ?? null,
                'amount'          => $intent->amount / 100,
                'currency'        => strtoupper($intent->currency),
                'allocation_type' => $allocation,
            ],
        );
    }
}
