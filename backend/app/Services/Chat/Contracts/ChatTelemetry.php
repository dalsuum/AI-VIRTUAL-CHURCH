<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\ChatResponse;

/**
 * Observability sink for the orchestration: timing/outcome per turn, keyed by
 * correlation id, never carrying message bodies or secrets. Distinct from
 * InferenceMetrics (which covers the provider call) — this measures the WHOLE pipeline.
 */
interface ChatTelemetry
{
    public function started(ChatContext $context): void;

    public function stepTimed(ChatContext $context, string $step, int $millis): void;

    /**
     * Knowledge-stage trace: provenance reason (POPULATED / NO_MATCH / FAILURE / DISABLED),
     * retrieval confidence, snippet count and latency — the signal that explains "RAG works
     * but sometimes feels off". Carries no snippet text.
     */
    public function knowledgeRetrieved(ChatContext $context, string $reason, float $confidence, int $snippets, int $millis): void;

    public function completed(ChatContext $context, ChatResponse $response): void;

    public function failed(ChatContext $context, \Throwable $error): void;
}
