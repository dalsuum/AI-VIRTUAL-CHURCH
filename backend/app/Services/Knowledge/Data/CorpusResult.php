<?php

namespace App\Services\Knowledge\Data;

/**
 * The outcome of querying ONE corpus: the fused (keyword+vector) chunks plus per-branch error
 * flags. Carrying the flags — rather than swallowing them silently — is what lets the platform
 * distinguish "no match" from "a branch was down", the core of the failure contract.
 */
final class CorpusResult
{
    /** @param list<RetrievedChunk> $chunks */
    public function __construct(
        public readonly array $chunks,
        public readonly bool $vectorError = false,
        public readonly bool $keywordError = false,
        public readonly int $vectorHitCount = 0,
        public readonly int $keywordHitCount = 0,
        public readonly int $embeddingLatencyMs = 0,
        public readonly int $vectorLatencyMs = 0,
        public readonly int $keywordLatencyMs = 0,
    ) {}

    /** True when the corpus produced nothing because BOTH branches failed. */
    public function totallyFailed(): bool
    {
        return $this->chunks === [] && $this->vectorError && $this->keywordError;
    }

    public function degraded(): bool
    {
        return $this->vectorError || $this->keywordError;
    }
}
