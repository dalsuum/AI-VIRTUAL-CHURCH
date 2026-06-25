<?php

namespace App\Services\Knowledge;

use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Chat\Data\KnowledgeContext;
use App\Services\Knowledge\Retrieval\ContextBuilder;
use App\Services\Knowledge\Retrieval\RetrievalOrchestrator;
use Psr\Log\LoggerInterface;

/**
 * The Knowledge Platform's single public face — it IMPLEMENTS the Chat layer's existing
 * KnowledgeRetriever seam, so swapping it in for NullKnowledgeRetriever is one binding change
 * and the Chat Orchestrator is untouched. It runs the retrieval orchestrator then the context
 * builder, returning ONLY a KnowledgeContext (the type the Prompt Builder consumes).
 *
 * Resilience: retrieval enriches a prompt but must never break a chat turn. Any failure
 * (vector store down, embeddings unavailable) is logged and degraded to an empty context, so
 * the conversation continues guard-checked and ungrounded rather than erroring.
 */
final class HybridKnowledgeRetriever implements KnowledgeRetriever
{
    public function __construct(
        private readonly RetrievalOrchestrator $orchestrator,
        private readonly ContextBuilder $builder,
        private readonly LoggerInterface $log,
        private readonly \App\Services\Observability\Contracts\Tracer $tracer = new \App\Services\Observability\NullTracer(),
    ) {}

    public function retrieve(string $query, array $filters = []): KnowledgeContext
    {
        return $this->tracer->span('retrieval.hybrid', fn () => $this->doRetrieve($query, $filters));
    }

    /** @param array<string,mixed> $filters */
    private function doRetrieve(string $query, array $filters): KnowledgeContext
    {
        try {
            // Always enforce permission scoping at the retrieval boundary.
            $filters['permissions'] ??= 'public';

            $outcome = $this->orchestrator->retrieve($query, $filters);

            // Annotate the retrieval span with observational signals (no chunk text).
            $this->tracer->annotate($outcome->diagnostics + [
                'retrieval.reason'    => $outcome->failed ? KnowledgeContext::EMPTY_FAILURE : ($outcome->chunks === [] ? KnowledgeContext::EMPTY_NO_MATCH : KnowledgeContext::POPULATED),
                'retrieval.degraded'  => $outcome->degraded,
                'retrieval.out_count' => count($outcome->chunks),
            ]);

            if ($outcome->degraded || $outcome->failed) {
                $this->log->warning('knowledge.retrieval_degraded', $outcome->diagnostics + ['failed' => $outcome->failed]);
            }

            // Failure classification lives HERE, not in the (pure) ContextBuilder: an empty
            // result caused by a backend error is EMPTY_DUE_TO_FAILURE, distinct from a clean
            // no-match. A degraded-but-non-empty result is still POPULATED (built normally).
            if ($outcome->chunks === [] && $outcome->failed) {
                return KnowledgeContext::failure();
            }

            return $this->tracer->span('context.build', fn () => $this->builder->build($outcome->chunks), [
                'context.input_count' => count($outcome->chunks),
            ]);
        } catch (\Throwable $e) {
            // Defensive backstop: any unexpected escape is treated as a system failure, never
            // a chat failure. Guards can refuse rather than answer ungrounded on FAILURE.
            $this->log->warning('knowledge.retrieve_failed', ['error' => $e->getMessage()]);

            return KnowledgeContext::failure();
        }
    }
}
