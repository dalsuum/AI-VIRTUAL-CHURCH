<?php

namespace App\Services\Knowledge\Retrieval;

use App\Services\Knowledge\Contracts\CorpusRetriever;
use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Data\CorpusResult;

/**
 * Generic hybrid retriever for a SINGLE corpus: runs keyword search and vector search in
 * parallel-of-intent and fuses them with RRF. One configurable class serves every corpus
 * (bible, sermon, prayer, policy, document) — instantiated per corpus in the service provider —
 * which avoids six near-identical classes while still presenting distinct CorpusRetriever
 * instances to the orchestrator (DRY without losing the per-corpus seam).
 */
final class HybridCorpusRetriever implements CorpusRetriever
{
    public function __construct(
        private readonly string $corpus,
        private readonly KeywordIndex $keyword,
        private readonly VectorStore $vectors,
        private readonly EmbeddingService $embeddings,
        private readonly ResultMerger $merger,
    ) {}

    public function corpus(): string
    {
        return $this->corpus;
    }

    public function retrieve(string $query, int $k, array $filters = []): CorpusResult
    {
        // Each branch is isolated: a vector outage must not take down the keyword path, and
        // vice-versa. Errors are recorded as flags, never thrown — this is the invariant
        // "vector failure must never become chat failure".
        $keywordHits = [];
        $keywordError = false;
        try {
            $keywordHits = $this->keyword->search($this->corpus, $query, $k, $filters);
        } catch (\Throwable) {
            $keywordError = true;
        }

        $vectorHits = [];
        $vectorError = false;
        try {
            $embedded = $this->embeddings->embed([$query]);
            if (isset($embedded[0])) {
                $vectorHits = $this->vectors->search($this->corpus, $embedded[0], $k, $filters);
            }
        } catch (\Throwable) {
            $vectorError = true; // embedding OR vector search failed → vector branch down
        }

        return new CorpusResult(
            $this->merger->fuse([$keywordHits, $vectorHits]),
            vectorError: $vectorError,
            keywordError: $keywordError,
        );
    }
}
