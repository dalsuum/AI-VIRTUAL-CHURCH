<?php

namespace App\Services\Knowledge\Data;

/**
 * A chunk paired with retrieval provenance: the score, the method that surfaced it
 * ('keyword' | 'vector') and the corpus retriever it came from. Merge/dedup/rerank operate
 * on these; the Context Builder finally projects them into a KnowledgeContext.
 */
final class RetrievedChunk
{
    public function __construct(
        public readonly Chunk $chunk,
        public readonly float $score,
        public readonly string $method,
        public readonly string $corpus,
    ) {}

    public function withScore(float $score): self
    {
        return new self($this->chunk, $score, $this->method, $this->corpus);
    }
}
