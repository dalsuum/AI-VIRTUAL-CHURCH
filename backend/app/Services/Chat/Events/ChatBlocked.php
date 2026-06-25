<?php

namespace App\Services\Chat\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an input or output guardrail blocks a turn. Lets safety dashboards and audit
 * trails react without coupling the orchestrator to them. Carries the reason CODE only,
 * never the offending text.
 */
final class ChatBlocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $sessionId,
        public readonly int $userId,
        public readonly string $stage,   // 'input' | 'output'
        public readonly string $reason,
        public readonly string $correlationId,
    ) {}
}
