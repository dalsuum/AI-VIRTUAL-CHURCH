<?php

namespace App\Services\Chat\Data;

/**
 * Retrieved knowledge passed from the Knowledge Base layer into the Prompt Builder. The
 * orchestrator never inspects the snippets' meaning — it only forwards them.
 *
 * An empty context is valid, but WHY it is empty matters once Citation/Hallucination/
 * Theology guards run: "no relevant knowledge exists" and "the knowledge system is broken"
 * demand different guard behaviour. So an empty context carries a `reason`, and any context
 * carries a `confidence` (0..1, the top retrieval score) the guards can threshold on. This
 * DTO stays pure data — interpreting these signals is GUARD policy, never the builder's.
 *
 * @phpstan-type Snippet array{source:string,text:string,score?:float}
 */
final class KnowledgeContext
{
    public const POPULATED = 'POPULATED';
    public const EMPTY_NO_MATCH = 'EMPTY_DUE_TO_NO_MATCH';
    public const EMPTY_FAILURE = 'EMPTY_DUE_TO_FAILURE';
    public const DISABLED = 'DISABLED'; // RAG off (NullKnowledgeRetriever)

    /** @param list<array{source:string,text:string,score?:float}> $snippets */
    public function __construct(
        public readonly array $snippets = [],
        public readonly float $confidence = 0.0,
        public readonly string $reason = self::DISABLED,
    ) {}

    /** @param list<array{source:string,text:string,score?:float}> $snippets */
    public static function populated(array $snippets, float $confidence): self
    {
        return new self($snippets, $confidence, self::POPULATED);
    }

    /** No relevant knowledge found (retrieval ran, returned nothing). */
    public static function noMatch(): self
    {
        return new self([], 0.0, self::EMPTY_NO_MATCH);
    }

    /** Retrieval could not run (store down, embeddings unavailable). Distinct from no-match. */
    public static function failure(): self
    {
        return new self([], 0.0, self::EMPTY_FAILURE);
    }

    /** RAG not enabled for this turn. */
    public static function empty(): self
    {
        return new self([], 0.0, self::DISABLED);
    }

    public function isEmpty(): bool
    {
        return $this->snippets === [];
    }

    /** True only when retrieval failed — guards may choose to refuse rather than answer ungrounded. */
    public function failedToRetrieve(): bool
    {
        return $this->reason === self::EMPTY_FAILURE;
    }
}
