<?php

namespace App\Services\Chat\Events;

use App\Services\Chat\Data\ChatResponse;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a turn is fully persisted. Soft-path listeners (analytics, achievements,
 * the Journey dashboard) subscribe to this rather than the orchestrator calling them —
 * keeping the orchestrator closed to new post-completion concerns (Open/Closed).
 */
final class ChatCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $sessionId,
        public readonly int $userId,
        public readonly string $capability,
        public readonly ChatResponse $response,
    ) {}
}
