<?php

namespace App\Domains\Invitations\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for invitation domain events. Names are PAST TENSE — they record a business
 * fact that already happened (InvitationSent, not SendInvitation). Payloads are plain
 * scalars (never a mutable model instance) so the contract is safe to queue, version
 * and consume externally.
 *
 * FROZEN public contract — names and payload shape don't change underneath subscribers:
 *   invitationId  — UUID of the invitation
 *   correlationId — threads the whole invitation → session → notification → audit →
 *                   analytics workflow; downstream MUST preserve it, never regenerate
 *   occurredAt    — when the fact happened (event ordering / analytics)
 *
 * InvitationService is the sole publisher.
 */
abstract class InvitationEvent
{
    use Dispatchable;

    public readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $invitationId,
        public readonly string $correlationId,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
