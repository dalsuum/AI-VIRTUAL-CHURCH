<?php

namespace App\Services\Chat\Data;

use App\Models\User;

/**
 * The immutable input to the Chat Orchestrator — the ONE thing a controller passes in.
 * It carries only what the user actually supplies plus identity/correlation; everything
 * else (persona, system prompt, model, provider) is resolved server-side inside the
 * orchestrator. User text lives in a single field and is always treated as DATA.
 */
final class ChatRequest
{
    public function __construct(
        public readonly User $user,
        public readonly string $sessionType,
        public readonly string $message,
        public readonly ?string $sessionId = null,
        public readonly ?string $languageHint = null,
        public readonly bool $stream = false,
        public readonly string $correlationId = '',
        /** Opaque per-session stream token used by input guardrails for audit hashing. */
        public readonly ?string $sessionToken = null,
        /** @var array<string,mixed> non-authoritative client metadata (locale, device). */
        public readonly array $clientMeta = [],
    ) {}
}
