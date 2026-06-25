<?php

namespace App\Services\Inference;

use App\Services\Inference\Data\InferenceResponse;
use Illuminate\Support\Facades\Log;

/**
 * Records per-call inference telemetry: latency, token usage, success/failure per
 * provider+model. Kept deliberately thin — it logs structured events (scrapeable into
 * Prometheus/Loki) and never the prompt or completion text, so no user content or
 * secret can leak through metrics. Correlation id ties a call back to its request.
 *
 * Persisting billable usage is the Persistence layer's job (AiUsageLedger); this is
 * operational telemetry only.
 */
class InferenceMetrics
{
    public function success(InferenceResponse $response, ?string $correlationId): void
    {
        Log::channel(config('inference.metrics_channel', 'stack'))->info('inference.success', [
            'provider'      => $response->providerName,
            'model'         => $response->model,
            'latency_ms'    => $response->latencyMs,
            'prompt_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
            'correlation_id' => $correlationId,
        ]);
    }

    public function failure(string $provider, string $reason, ?string $correlationId): void
    {
        Log::channel(config('inference.metrics_channel', 'stack'))->warning('inference.failure', [
            'provider'      => $provider,
            'reason'        => $reason,
            'correlation_id' => $correlationId,
        ]);
    }

    public function fallback(string $from, string $to, ?string $correlationId): void
    {
        Log::channel(config('inference.metrics_channel', 'stack'))->notice('inference.fallback', [
            'from'          => $from,
            'to'            => $to,
            'correlation_id' => $correlationId,
        ]);
    }
}
