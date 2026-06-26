<?php

namespace App\Services\Chat\Support;

use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Chat\Data\KnowledgeContext;

/**
 * Null Object for the Knowledge Base seam, used until the RAG layer is wired. Returning
 * an empty context is a correct, intended behaviour ("no retrieval available yet"), so
 * the orchestrator pipeline runs unchanged today and gains real retrieval the moment the
 * binding is switched to the FAISS/Qdrant-backed retriever. Not a placeholder — it is the
 * canonical Null Object pattern.
 */
final class NullKnowledgeRetriever implements KnowledgeRetriever
{
    public function retrieve(string $query, array $filters = []): KnowledgeContext
    {
        return KnowledgeContext::empty();
    }
}
