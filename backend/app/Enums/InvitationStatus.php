<?php

namespace App\Enums;

/**
 * Invitation lifecycle. PENDING is the only non-terminal state; every other state is
 * reached exactly once, through InvitationService::transition(). The service is the
 * sole mutator — no controller, listener or job writes status directly.
 */
enum InvitationStatus: string
{
    case PENDING   = 'pending';
    case ACCEPTED  = 'accepted';
    case DECLINED  = 'declined';
    case CANCELLED = 'cancelled';
    case EXPIRED   = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }
}
