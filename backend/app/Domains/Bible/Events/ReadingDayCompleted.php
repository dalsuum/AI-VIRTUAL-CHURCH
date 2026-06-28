<?php

namespace App\Domains\Bible\Events;

use DateTimeImmutable;

/**
 * A user finished their current plan day. Carries the LOCAL date of completion (the
 * streak is computed in the user's timezone) plus stable references to the day.
 */
class ReadingDayCompleted extends ReadingEvent
{
    public function __construct(
        int $userId,
        public readonly string $localDate,      // Y-m-d in the user's timezone
        public readonly int $readingPlanId,
        public readonly int $sequence,
        public readonly string $daySlug,        // stable per-day id (e.g. "day-001")
        ?string $correlationId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($userId, $correlationId, $occurredAt);
    }
}
