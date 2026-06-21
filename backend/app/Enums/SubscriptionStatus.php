<?php

namespace App\Enums;

/** Lifecycle state of a user's subscription, kept explicit rather than date-inferred. */
enum SubscriptionStatus: string
{
    case ACTIVE    = 'active';
    case TRIAL     = 'trial';
    case GRACE     = 'grace';      // paid plan, payment failing — access retained briefly
    case EXPIRED   = 'expired';
    case CANCELLED = 'cancelled';  // will not renew; access until period end

    /** Whether the paid entitlements should still be honoured. */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::ACTIVE, self::TRIAL, self::GRACE, self::CANCELLED], true);
    }
}
