<?php

namespace App\Services\Knowledge\Retrieval;

use App\Services\Knowledge\Contracts\CorpusRetriever;
use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Contracts\ManagesCollections;
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
        // Skip corpora whose backing collection isn't provisioned yet: no embedding, keyword or
        // vector calls, and no false "degraded" flag from a guaranteed 404. New collections light
        // up automatically once ingested (existence is cached briefly). Only stores that own their
        // collections (Qdrant) report this; in-memory/test stores always retrieve.
        if ($this->vectors instanceof ManagesCollections && ! $this->vectors->hasCollection($this->corpus)) {
            return new CorpusResult([]);
        }

        // Each branch is isolated: a vector outage must not take down the keyword path, and
        // vice-versa. Errors are recorded as flags, never thrown — this is the invariant
        // "vector failure must never become chat failure".
        $keywordHits = [];
        $keywordError = false;
        $keywordLatencyMs = 0;
        try {
            $keywordStart = microtime(true);
            $keywordHits = $this->keyword->search($this->corpus, $query, $k, $filters);
        } catch (\Throwable) {
            $keywordError = true;
        } finally {
            $keywordLatencyMs = (int) round((microtime(true) - ($keywordStart ?? microtime(true))) * 1000);
        }

        $vectorHits = [];
        $vectorError = false;
        $embeddingLatencyMs = 0;
        $vectorLatencyMs = 0;
        try {
            $embeddingStart = microtime(true);
            $embedded = $this->embeddings->embed([$query]);
            $embeddingLatencyMs = (int) round((microtime(true) - $embeddingStart) * 1000);
            if (isset($embedded[0])) {
                $vectorStart = microtime(true);
                $vectorHits = $this->vectors->search($this->corpus, $embedded[0], $k, $filters);
                $vectorLatencyMs = (int) round((microtime(true) - $vectorStart) * 1000);
            }
        } catch (\Throwable) {
            $vectorError = true; // embedding OR vector search failed → vector branch down
            $embeddingLatencyMs = $embeddingLatencyMs ?: (int) round((microtime(true) - ($embeddingStart ?? microtime(true))) * 1000);
        }

        return new CorpusResult(
            $this->merger->fuse([$keywordHits, $vectorHits]),
            vectorError: $vectorError,
            keywordError: $keywordError,
            vectorHitCount: count($vectorHits),
            keywordHitCount: count($keywordHits),
            embeddingLatencyMs: $embeddingLatencyMs,
            vectorLatencyMs: $vectorLatencyMs,
            keywordLatencyMs: $keywordLatencyMs,
        );
    }
}
