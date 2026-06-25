<?php

namespace App\Services\Chat\Data;

/**
 * The immutable result the orchestrator hands back to the controller, which serialises
 * it to JSON. A blocked request (input/output guardrail) is still a valid ChatResponse
 * with blocked=true and a safe message — the controller never branches on internals.
 */
final class ChatResponse
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $text,
        public readonly string $language,
        public readonly string $capability,
        public readonly string $correlationId,
        public readonly bool $blocked = false,
        public readonly ?string $blockReason = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $latencyMs = 0,
    ) {}

    public static function blocked(string $sessionId, string $language, string $capability, string $cid, string $text, string $reason): self
    {
        return new self($sessionId, $text, $language, $capability, $cid, blocked: true, blockReason: $reason);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'session_id'     => $this->sessionId,
            'message'        => $this->text,
            'language'       => $this->language,
            'capability'     => $this->capability,
            'blocked'        => $this->blocked,
            'block_reason'   => $this->blockReason,
            'correlation_id' => $this->correlationId,
            'usage'          => [
                'prompt'     => $this->promptTokens,
                'completion' => $this->completionTokens,
            ],
        ];
    }
}
