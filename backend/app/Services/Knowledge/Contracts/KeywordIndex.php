<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * Lexical (keyword/BM25-style) retrieval — the other half of hybrid search. Kept separate
 * from the vector store because the two answer different question shapes (exact terms vs.
 * semantic similarity); merging both consistently beats either alone.
 */
interface KeywordIndex
{
    /**
     * @param array<string,mixed> $filters
     * @return list<RetrievedChunk>
     */
    public function search(string $collection, string $query, int $k, array $filters = []): array;
}
