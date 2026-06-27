<?php

namespace App\Domains\Invitations\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for invitation domain events. Names are PAST TENSE — they record a business
 * fact that already happened (InvitationSent, not SendInvitation). Names and the
 * (invitationId, correlationId) payload are a FROZEN public contract: notifications,
 * session creation, audit and analytics subscribe in later phases and must never have
 * the shape changed underneath them. New facts get NEW events.
 *
 * correlationId threads the whole invitation → session → notification → audit →
 * analytics workflow together. InvitationService is the sole publisher.
 */
abstract class InvitationEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $invitationId,
        public readonly string $correlationId,
    ) {
    }
}
