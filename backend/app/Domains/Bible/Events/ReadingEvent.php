<?php

namespace App\Domains\Bible\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Base for Bible-reading domain events. Past-tense facts, scalar payloads only.
 * FROZEN contract — see ADR 0002. correlationId threads a reading workflow (a day's
 * completion and everything it spawns: streak update, feed, analytics, AI reflection);
 * occurredAt is the fact time. Only the owning service/scheduler publishes these;
 * enrollment never fabricates completion events.
 *
 *   userId        — whose reading
 *   correlationId — shared id for this action's fan-out
 *   occurredAt    — when it happened
 */
abstract class ReadingEvent
{
    use Dispatchable;

    public readonly string $correlationId;
    public readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $userId,
        ?string $correlationId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->correlationId = $correlationId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
