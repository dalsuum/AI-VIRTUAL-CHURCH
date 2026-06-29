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
        return response()->json([
            'raw_query'        => $rawQuery,
            'normalized_query' => $normalizedQuery,
            'embedding'        => $embeddingInfo,
            'corpora'          => $diag['retrieval.corpora'] ?? [],
            'rrf_count'        => $diag['retrieval.rrf_count'] ?? 0,
            'reranked_chunks'  => array_map(function ($chunk) {
                return [
                    'chunk_id'     => $chunk->chunk->id,
                    'corpus'       => $chunk->corpus,
                    'source'       => $chunk->chunk->metadata->source ?? null,
                    'reference'    => $chunk->chunk->metadata->reference ?? null,
                    'language'     => $chunk->chunk->metadata->language ?? null,
                    'method'       => $chunk->method,
                    'score'        => round($chunk->score, 4),
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
