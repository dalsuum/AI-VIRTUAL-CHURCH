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
}
