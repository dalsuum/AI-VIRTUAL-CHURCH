<?php

namespace App\Services\Inference\Data;

/**
 * Token accounting for a single inference call. Providers populate what they can;
 * unknown values stay 0. Used by InferenceMetrics and the persistence layer — never
 * for orchestration decisions.
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {}

    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /** @return array{prompt:int,completion:int,total:int} */
    public function toArray(): array
    {
        return [
            'prompt'     => $this->promptTokens,
            'completion' => $this->completionTokens,
            'total'      => $this->total(),
        ];
    }
}
