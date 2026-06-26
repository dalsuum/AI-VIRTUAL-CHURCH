<?php

namespace App\Services\Knowledge\Data;

/**
 * The whole-query retrieval outcome the orchestrator returns: the final ranked chunks plus a
 * classification the KnowledgeRetriever maps to KnowledgeContext.reason.
 *
 *   chunks non-empty            → POPULATED (possibly degraded)
 *   empty + any branch errored  → failed=true  → EMPTY_DUE_TO_FAILURE
 *   empty + no errors           → failed=false → EMPTY_DUE_TO_NO_MATCH
 *
 * `diagnostics` carries the booleans the knowledge layer logs (vector_store_error,
 * keyword_fallback_used, …) — never message text.
 */
final class RetrievalOutcome
{
    /**
     * @param list<RetrievedChunk> $chunks
     * @param array<string,mixed> $diagnostics
     */
    public function __construct(
        public readonly array $chunks,
        public readonly bool $degraded,
        public readonly bool $failed,
        public readonly array $diagnostics = [],
    ) {}
}
