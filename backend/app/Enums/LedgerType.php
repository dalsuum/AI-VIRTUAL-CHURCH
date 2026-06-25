<?php

namespace App\Enums;

/** Classifies a token_ledger row. Signed `amount` still carries the direction; this
 *  records the *intent* so accounting and support can filter cleanly. */
enum LedgerType: string
{
    case REFILL      = 'refill';      // monthly plan grant
    case GRANT       = 'grant';       // ad-hoc credit (e.g. signup bonus)
    case RESERVATION = 'reservation'; // debit, held pending an AI request
    case SPEND       = 'spend';       // reservation committed
    case ROLLBACK    = 'rollback';    // reservation refunded
    case PURCHASE    = 'purchase';    // top-up bought via Stripe
    case REFUND      = 'refund';      // purchase reversed
    case ADJUSTMENT  = 'adjustment';  // admin correction
}
