<?php

namespace App\Domains\Friends\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for the friendship domain events. These event NAMES and their (actorId,
 * targetId) payload are a FROZEN public contract: notifications, the activity feed,
 * analytics and AI memory subscribe to them in later phases and must never have the
 * shape changed underneath them. New facts get NEW events, never edits to these.
 *
 *   actorId  — the user who performed the action
 *   targetId — the other user in the pair
 *
 * FriendshipService is the sole publisher.
 */
abstract class FriendshipEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $actorId,
        public readonly int $targetId,
    ) {
    }
}
