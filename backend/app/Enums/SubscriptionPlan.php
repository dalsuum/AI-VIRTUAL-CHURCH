<?php

namespace App\Enums;

/**
 * Billing tier. Distinct from the staff-privilege role on the user. New tiers
 * (premium_yearly, church, enterprise) slot in here; PlanService maps each to its
 * rules so no controller hard-codes a plan string.
 */
enum SubscriptionPlan: string
{
    case GUEST   = 'guest';
    case MEMBER  = 'member';
    case PREMIUM = 'premium';

    /** Paid tiers — used when deciding whether a Stripe subscription is involved. */
    public function isPaid(): bool
    {
        return $this === self::PREMIUM;
    }
}
