<?php

namespace App\Services\Inference\Data;

/**
 * A fully-assembled inference request. This DTO is the trust boundary of the
 * inference layer: by the time a request reaches a provider, the prompt has ALREADY
 * been built (Prompt Builder layer), guarded (Input Guardrails) and enriched with KB
 * context. The inference layer does NOT build prompts, route languages for business
 * reasons, or apply safety — it only transports messages to a model and returns text.
 *
 * `messages` use the portable role/content shape ({role: system|user|assistant}).
 * Each provider adapter maps this to its own wire format.
 *
 * @phpstan-type Message array{role:string,content:string}
 */
final class InferenceRequest
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options provider-agnostic knobs (top_p, stop, …)
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $model = null,
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 0.7,
        public readonly bool $stream = false,
        public readonly ?string $language = null,
        public readonly ?string $purpose = null,
        public readonly ?string $correlationId = null,
        public readonly array $options = [],
    ) {}

    public function withModel(?string $model): self
    {
        return new self(
            $this->messages, $model, $this->maxTokens, $this->temperature,
            $this->stream, $this->language, $this->purpose, $this->correlationId, $this->options,
        );
    }

    /** Convenience for single-prompt callers; system prompt is optional. */
    public static function prompt(
        string $user,
        ?string $system = null,
        ?string $model = null,
        int $maxTokens = 1024,
        ?string $correlationId = null,
    ): self {
        $messages = [];
        if ($system !== null) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $user];

        return new self($messages, $model, $maxTokens, correlationId: $correlationId);
    }
}
