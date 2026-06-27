<?php

namespace App\Domains\Bible\Events;

use DateTimeImmutable;

/**
 * A daily-reading reminder was delivered for a slot. Emitted by the reminder scheduler
 * AFTER the notification is sent — a record of the fact, for analytics. The scheduler
 * is the only publisher; nothing downstream re-sends from this event.
 */
class ReadingReminderSent extends ReadingEvent
{
    public function __construct(
        int $userId,
        public readonly string $slot,           // morning | afternoon | evening
        ?string $correlationId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($userId, $correlationId, $occurredAt);
    }
}
