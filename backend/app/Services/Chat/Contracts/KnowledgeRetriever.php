<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\KnowledgeContext;

/**
 * The seam into the Knowledge Base (RAG) layer. The orchestrator passes a query +
 * filters and receives ranked snippets; it never knows whether retrieval was keyword,
 * embedding or hybrid. Until the KB layer ships, NullKnowledgeRetriever satisfies this
 * with an empty context — a valid behaviour, not a placeholder.
 */
interface KnowledgeRetriever
{
    /** @param array<string,mixed> $filters e.g. ['language'=>'en','collection'=>'bible'] */
    public function retrieve(string $query, array $filters = []): KnowledgeContext;
}
