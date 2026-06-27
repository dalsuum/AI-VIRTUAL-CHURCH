<?php

namespace App\Domains\Bible\Events;

use DateTimeImmutable;

/** A user finished the final day of a plan. */
class ReadingPlanCompleted extends ReadingEvent
{
    public function __construct(
        int $userId,
        public readonly int $readingPlanId,
        ?string $correlationId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($userId, $correlationId, $occurredAt);
    }
}
