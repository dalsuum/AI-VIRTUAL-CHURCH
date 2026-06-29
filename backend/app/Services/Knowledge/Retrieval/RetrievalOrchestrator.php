<?php

namespace App\Services\Knowledge\Retrieval;

use App\Services\Knowledge\Contracts\CorpusRetriever;
use App\Services\Knowledge\Contracts\Reranker;
use App\Services\Knowledge\Data\RetrievalOutcome;

/**
 * Coordinates the explicit retrieval stages across all corpora:
 *
 *   normalize → (per-corpus hybrid retrieve) → fuse → rerank → top-k
 *
 * It owns no storage and no embedding logic — it composes CorpusRetrievers, the ResultMerger
 * and a Reranker. This is where "improve ranking later" happens without touching callers.
 */
final class RetrievalOrchestrator
{
    /** @param iterable<CorpusRetriever> $corpora */
    public function __construct(
        private readonly iterable $corpora,
        private readonly QueryNormalizer $normalizer,
        private readonly ResultMerger $merger,
        private readonly Reranker $reranker,
        private readonly int $perCorpusK = 8,
        private readonly int $finalK = 6,
        private readonly \App\Services\Observability\Contracts\Tracer $tracer = new \App\Services\Observability\NullTracer(),
    ) {}

    /**
     * @param array<string,mixed> $filters
     * @param list<string>|null $only restrict to specific corpus keys (null = all)
     */
    public function retrieve(string $query, array $filters = [], ?array $only = null): RetrievalOutcome
    {
        $query = $this->normalizer->normalize($query);

        $lists = [];
        $corpusDiagnostics = [];
        $vectorError = false;
        $keywordError = false;
        foreach ($this->corpora as $corpus) {
            if ($only !== null && ! in_array($corpus->corpus(), $only, true)) {
                continue;
            }
            $result = $corpus->retrieve($query, $this->perCorpusK, $filters);
            $lists[] = $result->chunks;
            $corpusDiagnostics[$corpus->corpus()] = [
                'keyword_hits' => $result->keywordHitCount,
                'vector_hits'  => $result->vectorHitCount,
                'fused_hits'   => count($result->chunks),
                'embedding_latency_ms' => $result->embeddingLatencyMs,
                'vector_latency_ms'    => $result->vectorLatencyMs,
                'keyword_latency_ms'   => $result->keywordLatencyMs,
                'keyword_error' => $result->keywordError,
                'vector_error'  => $result->vectorError,
            ];
            $vectorError = $vectorError || $result->vectorError;
            $keywordError = $keywordError || $result->keywordError;
        }

        $fused = $this->tracer->span('rrf.fusion', function () use ($lists) {
            $f = $this->merger->fuse($lists);
            $this->tracer->annotate(['rrf.output_count' => count($f)]);

            return $f;
        }, ['rrf.input_counts' => array_map('count', $lists)]);

        $ranked = $this->tracer->span('rerank', function () use ($query, $fused) {
            $r = array_slice($this->reranker->rerank($query, $fused), 0, $this->finalK);
            $this->tracer->annotate(['rerank.output' => count($r)]);

            return $r;
        }, ['rerank.input' => count($fused), 'rerank.model' => 'heuristic-v1']);

        $anyError = $vectorError || $keywordError;

        return new RetrievalOutcome(
            chunks: $ranked,
            degraded: $ranked !== [] && $anyError,
            failed: $ranked === [] && $anyError,
            diagnostics: [
                'vector_store_error'    => $vectorError,
                'keyword_fallback_used' => $vectorError && ! $keywordError,
                'keyword_error'         => $keywordError,
                'retrieval.corpora'     => $corpusDiagnostics,
                'retrieval.rrf_count'   => count($fused),
                'retrieval.final_chunks' => array_map(static fn ($chunk) => [
                    'chunk_id'  => $chunk->chunk->id,
                    'corpus'    => $chunk->corpus,
                    'source'    => $chunk->chunk->metadata->source,
                    'reference' => $chunk->chunk->metadata->reference,
                    'method'    => $chunk->method,
                    'score'     => round($chunk->score, 4),
                ], $ranked),
            ],
        );
    }
}
