<?php

namespace App\Services\Inference;

use App\Services\Inference\Contracts\InferenceProvider;
use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\ProviderHealth;
use App\Services\Inference\Exceptions\CircuitOpenException;
use App\Services\Inference\Exceptions\ProviderException;

/**
 * Decorator that adds resilience around any InferenceProvider WITHOUT the concrete
 * provider knowing about it: circuit-breaker gating, bounded retries with backoff, and
 * success/failure recording. This keeps OllamaProvider/ClaudeProvider focused purely on
 * wire protocol (single responsibility) while the policy lives in one reusable place.
 *
 * The gateway composes the fallback chain ON TOP of these; this class governs a single
 * provider's own attempts only.
 */
class ResilientProvider implements InferenceProvider
{
    public function __construct(
        private readonly InferenceProvider $inner,
        private readonly CircuitBreaker $breaker,
        private readonly int $maxRetries = 2,
        private readonly int $backoffMs = 250,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function complete(InferenceRequest $request): InferenceResponse
    {
        $this->guard();

        $attempt = 0;
        while (true) {
            try {
                $response = $this->inner->complete($request);
                $this->breaker->recordSuccess($this->name());

                return $response;
            } catch (ProviderException $e) {
                $this->breaker->recordFailure($this->name());

                if (! $e->retryable || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                usleep($this->backoffMs * 1000 * (2 ** $attempt));
                $attempt++;
            }
        }
    }

    public function stream(InferenceRequest $request): \Generator
    {
        // Streaming is not retried mid-flight (partial tokens may already be on the wire);
        // we only gate on the breaker and record the terminal outcome.
        $this->guard();

        try {
            $response = yield from $this->inner->stream($request);
            $this->breaker->recordSuccess($this->name());

            return $response;
        } catch (ProviderException $e) {
            $this->breaker->recordFailure($this->name());
            throw $e;
        }
    }

    public function health(): ProviderHealth
    {
        return $this->inner->health();
    }

    private function guard(): void
    {
        if (! $this->breaker->allows($this->name())) {
            throw new CircuitOpenException($this->name());
        }
    }
}
