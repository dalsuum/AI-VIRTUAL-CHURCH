<?php

namespace App\Domains\Friends\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Base for the friendship domain events. Payloads are plain scalars (never a mutable
 * model instance) so the contract is safe to queue, version and consume externally.
 *
 * FROZEN public contract — names and payload shape don't change underneath subscribers:
 *   actorId       — the user who performed the action
 *   targetId      — the other user in the pair
 *   correlationId — id for THIS business action; every record it spawns (notification,
 *                   activity feed, analytics, AI memory) reuses it for tracing/idempotency.
 *                   Downstream MUST preserve it, never regenerate.
 *   occurredAt    — when the fact happened (event ordering / analytics)
 *
 * FriendshipService is the sole publisher.
 */
abstract class FriendshipEvent
{
    use Dispatchable;

    public readonly string $correlationId;
    public readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly int $actorId,
        public readonly int $targetId,
        ?string $correlationId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->correlationId = $correlationId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }
}
