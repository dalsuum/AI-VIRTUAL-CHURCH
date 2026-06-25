<?php

namespace App\Services\SessionState;

/**
 * Immutable input for SessionStateStore::appendNode. Modules describe *what* to record;
 * the store decides branch/seq/lineage. Keeps callers from touching graph internals.
 */
final class SessionNodeData
{
    public function __construct(
        public readonly string $type = 'message',     // message|checkpoint|fork|system_event
        public readonly ?string $sender = null,        // user|assistant|moderator|…
        public readonly ?string $content = null,
        public readonly ?array $metadata = null,
        public readonly ?int $tokenUsage = null,
    ) {}

    public static function message(string $sender, string $content, ?array $metadata = null, ?int $tokenUsage = null): self
    {
        return new self('message', $sender, $content, $metadata, $tokenUsage);
    }

    /**
     * A non-message graph event (Phase 2): service milestones, music playback events,
     * study round markers. `event` is stored as the node content; `metadata` carries the
     * structured payload. `sender` defaults to 'system'.
     */
    public static function systemEvent(string $event, ?array $metadata = null, string $sender = 'system'): self
    {
        return new self('system_event', $sender, $event, $metadata, null);
    }
}
