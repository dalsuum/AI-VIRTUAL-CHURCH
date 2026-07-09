<?php

namespace App\Domains\Bible\Events;

use DateTimeImmutable;

/**
 * A shared reading session went live (planned → active) — the session-level event
 * reserved in the frozen reading ladder (ReadingReminderSent → ReadingSessionStarted →
 * … → ReadingPlanCompleted). userId is the leader who started it; correlationId is
 * the session's correlation_id, threading everything the session spawns.
 */
class ReadingSessionStarted extends ReadingEvent
{
    public function __construct(
        int $userId,
        public readonly string $sessionId,
        public readonly int $groupId,
        public readonly int $readingPlanId,
        ?string $correlationId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($userId, $correlationId, $occurredAt);
    }
}
