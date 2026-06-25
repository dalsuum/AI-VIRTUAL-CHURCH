<?php

namespace App\Http\Controllers;

use App\Services\Observability\Contracts\TraceStore;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retrieval/execution explainability — GET /api/v1/chat/debug/{correlationId}. It MATERIALISES a
 * request's trace from the store; it NEVER re-runs retrieval (a recompute would drift from what
 * actually happened under load). Staff-only: the route sits behind the 'staff' middleware. Output
 * is the recorded span tree — guardrails decisions, RRF/rerank counts, context budget, inference
 * latency — already free of chunk text and secrets by the Span attribute discipline.
 */
final class ChatDebugController extends Controller
{
    public function __construct(private readonly TraceStore $traces) {}

    public function show(string $correlationId): JsonResponse
    {
        abort_unless(config('observability.debug_endpoint'), Response::HTTP_NOT_FOUND);

        $trace = $this->traces->get($correlationId);
        if ($trace === null) {
            return response()->json([
                'error'   => 'trace_not_found',
                'message' => 'No trace recorded for this correlation id (tracing disabled, not sampled, or expired).',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'correlation_id' => $correlationId,
            'trace'          => $trace,
        ]);
    }
}
