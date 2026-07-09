<?php

namespace App\Domains\Invitations\Events;

use DateTimeImmutable;

/**
 * A user joined a group through a LINK invitation. Additive to the frozen event
 * contract: base fields are unchanged, plus the redeeming user (links have no
 * invitee, so the actor cannot be derived from the invitation row).
 *
 * Unlike ACCEPTED, redemption does not terminate the invitation — a link stays
 * PENDING until revoked, expired or exhausted, so one invitationId may emit many
 * InvitationRedeemed events (one per member who joined).
 */
class InvitationRedeemed extends InvitationEvent
{
    public function __construct(
        string $invitationId,
        string $correlationId,
        public readonly int $userId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($invitationId, $correlationId, $occurredAt);
    }
}
