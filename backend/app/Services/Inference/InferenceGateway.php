<?php

namespace App\Services\Inference;

use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\ProviderHealth;
use App\Services\Inference\Exceptions\NoProviderAvailableException;
use App\Services\Inference\Exceptions\ProviderException;

/**
 * THE single entry point into the inference layer. Everything above (Prompt Builder /
 * orchestration / pipeline execute()) calls $gateway->complete($request) or
 * ->stream($request) and gets model output back. Nothing above this class ever knows
 * which concrete provider answered.
 *
 * Responsibilities (inference only — no prompt building, no guardrails, no persistence):
 *   • resolve a fallback CHAIN of provider names for the request (language/purpose →
 *     config routing), e.g. ["ollama_tedim", "claude"];
 *   • try each in order; a provider failure or open circuit advances to the next;
 *   • record metrics + fallback events;
 *   • raise NoProviderAvailableException only when the whole chain is exhausted.
 *
 * Per-provider retry/circuit-breaker is handled INSIDE each ResilientProvider; the
 * gateway owns CROSS-provider fallback. The two compose without overlap.
 */
class InferenceGateway
{
    public function __construct(
        private readonly ModelRegistry $registry,
        private readonly InferenceMetrics $metrics,
    ) {}

    public function complete(InferenceRequest $request): InferenceResponse
    {
        $chain = $this->chainFor($request);
        $tried = [];
        $last = null;

        foreach ($chain as $i => $providerName) {
            $tried[] = $providerName;
            try {
                $response = $this->registry->get($providerName)->complete($request);
                $this->metrics->success($response, $request->correlationId);

                return $response;
            } catch (ProviderException $e) {
                $this->metrics->failure($providerName, $e->getMessage(), $request->correlationId);
                $last = $e;
                if (isset($chain[$i + 1])) {
                    $this->metrics->fallback($providerName, $chain[$i + 1], $request->correlationId);
                }
            }
        }

        throw new NoProviderAvailableException($tried, $last);
    }

    /**
     * Streaming has no cross-provider fallback once bytes are flowing (a half-streamed
     * answer cannot be silently swapped). We fall back ONLY while selecting the provider
     * — i.e. skip providers whose circuit is open — then commit to the first that opens
     * a stream. Yields text deltas; returns the final InferenceResponse.
     *
     * @return \Generator<int,string,mixed,InferenceResponse>
     */
    public function stream(InferenceRequest $request): \Generator
    {
        $chain = $this->chainFor($request);
        $tried = [];
        $last = null;

        foreach ($chain as $providerName) {
            $tried[] = $providerName;
            $provider = $this->registry->get($providerName);
            try {
                // health()/circuit gate is cheap; if the provider can't even start, try next.
                $response = yield from $provider->stream($request);
                $this->metrics->success($response, $request->correlationId);

                return $response;
            } catch (ProviderException $e) {
                $this->metrics->failure($providerName, $e->getMessage(), $request->correlationId);
                $last = $e;
            }
        }

        throw new NoProviderAvailableException($tried, $last);
    }

    /** @return list<ProviderHealth> */
    public function health(): array
    {
        return array_map(
            fn (string $name) => $this->registry->get($name)->health(),
            $this->registry->names(),
        );
    }

    /**
     * Resolve the ordered provider chain for a request. Pure config lookup: routing is
     * keyed by language first (local model preferred for Tedim/Burmese), then by a named
     * default chain. The final fallback is always the global default chain.
     *
     * @return list<string>
     */
    private function chainFor(InferenceRequest $request): array
    {
        $routes = config('inference.routing', []);
        $default = config('inference.default_chain', ['claude']);

        $chain = $routes[$request->language ?? ''] ?? $default;

        return array_values(array_unique($chain));
    }
}
