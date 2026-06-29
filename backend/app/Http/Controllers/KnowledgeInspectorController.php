<?php

namespace App\Http\Controllers;

use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\Retrieval\ContextBuilder;
use App\Services\Knowledge\Retrieval\QueryNormalizer;
use App\Services\Knowledge\Retrieval\RetrievalOrchestrator;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retrieval Inspector — runs a full query through the RAG pipeline and returns
 * a step-by-step trace so admins can understand why the system answered as it did.
 *
 * Exposed at POST /api/v1/admin/knowledge/inspect  (requires knowledge.view)
 */
final class KnowledgeInspectorController extends Controller
{
    public function inspect(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        $validated = $request->validate([
            'query'    => ['required', 'string', 'min:3', 'max:500'],
            'language' => ['nullable', 'string', 'max:8'],
            'corpora'  => ['nullable', 'array'],
            'corpora.*' => ['string'],
        ]);

        $rawQuery = $validated['query'];
        $language = $validated['language'] ?? 'en';
        $onlyCorpora = isset($validated['corpora']) && count($validated['corpora']) > 0
            ? $validated['corpora']
            : null;

        /** @var QueryNormalizer $normalizer */
        $normalizer = app(QueryNormalizer::class);
        /** @var EmbeddingService $embedder */
        $embedder = app(EmbeddingService::class);
        /** @var RetrievalOrchestrator $orchestrator */
        $orchestrator = app(RetrievalOrchestrator::class);
        /** @var ContextBuilder $contextBuilder */
        $contextBuilder = app(ContextBuilder::class);

        // ── Stage 1: Normalize ───────────────────────────────────────────────
        $normalizedQuery = $normalizer->normalize($rawQuery);

        // ── Stage 2: Embed ───────────────────────────────────────────────────
        $embeddingInfo = $this->embedQuery($embedder, $normalizedQuery);

        // ── Stage 3–5: Retrieve (vector + keyword + RRF + rerank) ───────────
        $filters = ['language' => $language];
        $outcome = $orchestrator->retrieve($normalizedQuery, $filters, $onlyCorpora);
        $diag    = $outcome->diagnostics;

        // ── Stage 6: Context builder ─────────────────────────────────────────
        $context = $contextBuilder->build($outcome->chunks);

        // ── Assemble trace ───────────────────────────────────────────────────
        $sourcePriority = (array) config('knowledge.source_priority', []);
        $droppedCount   = max(0, ($diag['retrieval.rrf_count'] ?? 0) - count($outcome->chunks));

        return response()->json([
            'raw_query'        => $rawQuery,
            'normalized_query' => $normalizedQuery,
            'embedding'        => $embeddingInfo,
            'corpora'          => $diag['retrieval.corpora'] ?? [],
            'rrf_count'        => $diag['retrieval.rrf_count'] ?? 0,
            'dropped_count'    => $droppedCount,
            'reranked_chunks'  => array_map(function ($chunk) use ($normalizedQuery, $sourcePriority) {
                return [
                    'chunk_id'     => $chunk->chunk->id,
                    'corpus'       => $chunk->corpus,
                    'source'       => $chunk->chunk->metadata->source ?? null,
                    'reference'    => $chunk->chunk->metadata->reference ?? null,
                    'language'     => $chunk->chunk->metadata->language ?? null,
                    'method'       => $chunk->method,
                    'score'        => round($chunk->score, 4),
                    'decisions'    => $this->chunkDecisions($normalizedQuery, $chunk, $sourcePriority),
                    'text_preview' => mb_substr($chunk->chunk->text, 0, 300),
                    'text_length'  => mb_strlen($chunk->chunk->text),
                ];
            }, $outcome->chunks),
            'context' => [
                'populated'  => !$context->isEmpty(),
                'confidence' => $context->confidence,
                'char_count' => array_sum(array_map(fn ($s) => mb_strlen($s['text']), $context->snippets ?? [])),
                'snippets'   => array_map(fn ($s) => [
                    'source' => $s['source'],
                    'score'  => $s['score'],
                    'text'   => mb_substr($s['text'], 0, 400),
                ], $context->snippets ?? []),
            ],
            'degraded' => $outcome->degraded,
            'failed'   => $outcome->failed,
        ]);
    }

    /**
     * Produce human-readable decision labels for a surviving chunk.
     * Mirrors HeuristicReranker's three scoring axes so each label maps to
     * the actual signal that influenced the final score.
     *
     * @param array<string,int|float> $sourcePriority
     * @return list<array{ok:bool,text:string}>
     */
    private function chunkDecisions(string $query, \App\Services\Knowledge\Data\RetrievedChunk $chunk, array $sourcePriority): array
    {
        $decisions = [];

        // ── Axis 1: retrieval method ─────────────────────────────────────
        if ($chunk->method === 'vector') {
            $decisions[] = ['ok' => true, 'text' => 'Vector similarity match'];
        } elseif ($chunk->method === 'keyword') {
            $decisions[] = ['ok' => true, 'text' => 'Keyword index match'];
        } else {
            $decisions[] = ['ok' => true, 'text' => 'Vector similarity match'];
            $decisions[] = ['ok' => true, 'text' => 'Keyword index match'];
        }

        // ── Axis 2: lexical coverage (same formula as HeuristicReranker) ──
        $terms    = $this->queryTerms($query);
        $coverage = $this->lexicalCoverage($terms, $chunk->chunk->text);
        if ($coverage >= 0.6) {
            $decisions[] = ['ok' => true,  'text' => sprintf('Strong lexical coverage (%.0f%% of query terms found)', $coverage * 100)];
        } elseif ($coverage >= 0.25) {
            $decisions[] = ['ok' => true,  'text' => sprintf('Partial lexical coverage (%.0f%% of query terms found)', $coverage * 100)];
        } else {
            $decisions[] = ['ok' => false, 'text' => sprintf('Low lexical coverage (%.0f%% of query terms found)', $coverage * 100)];
        }

        // ── Axis 3: source priority boost ────────────────────────────────
        // Check both source metadata and corpus name (same lookup order as the reranker).
        $priority = $sourcePriority[$chunk->chunk->metadata->source ?? '']
            ?? $sourcePriority[$chunk->corpus]
            ?? 0;
        if ($priority >= 80) {
            $decisions[] = ['ok' => true,  'text' => "High source priority ({$chunk->corpus}, weight {$priority})"];
        } elseif ($priority >= 40) {
            $decisions[] = ['ok' => true,  'text' => "Moderate source priority ({$chunk->corpus}, weight {$priority})"];
        } else {
            $decisions[] = ['ok' => false, 'text' => "Lower source priority ({$chunk->corpus}, weight {$priority})"];
        }

        return $decisions;
    }

    /** @return list<string>  3+ char tokens, lowercased, de-duped (matches HeuristicReranker::terms) */
    private function queryTerms(string $query): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($query), $m);

        return array_values(array_unique($m[0] ?? []));
    }

    /** Fraction of $terms that appear in $text (matches HeuristicReranker::coverage) */
    private function lexicalCoverage(array $terms, string $text): float
    {
        if ($terms === []) {
            return 0.0;
        }
        $haystack = mb_strtolower($text);
        $hits = 0;
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        return $hits / count($terms);
    }

    /** Embed the query and return a compact info array.  Falls back gracefully. */
    private function embedQuery(EmbeddingService $embedder, string $query): array
    {
        $start = microtime(true);
        try {
            $vectors = $embedder->embed([$query]);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $vector = $vectors[0] ?? [];
            $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

            return [
                'dims'       => count($vector),
                'preview'    => array_map(fn ($v) => round($v, 4), array_slice($vector, 0, 8)),
                'magnitude'  => round($magnitude, 4),
                'latency_ms' => $latencyMs,
                'model'      => $embedder->model(),
                'error'      => null,
            ];
        } catch (\Throwable $e) {
            return [
                'dims'       => 0,
                'preview'    => [],
                'magnitude'  => 0,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'model'      => $embedder->model(),
                'error'      => $e->getMessage(),
            ];
        }
    }
}
