<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * Re-scores fused candidates against the query for final ordering. The default is a fast
 * lexical reranker; a cross-encoder (run in the worker) can replace it behind this same seam
 * without changing the orchestrator — exactly the "improve ranking later" extension point.
 */
interface Reranker
{
    /**
     * @param list<RetrievedChunk> $candidates
     * @return list<RetrievedChunk> reordered, best first
     */
    public function rerank(string $query, array $candidates): array;
}
