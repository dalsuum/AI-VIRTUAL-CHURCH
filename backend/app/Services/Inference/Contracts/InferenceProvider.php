<?php

namespace App\Services\Inference\Contracts;

use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\ProviderHealth;

/**
 * The single contract every inference backend implements — Ollama (Tedim/Burmese),
 * Claude, and future OpenAI/Gemini/DeepSeek. Adding a model means adding ONE class
 * that implements this interface; no caller above the inference layer changes.
 *
 * Responsibility is strictly inference: turn a built request into model output.
 * Implementations MUST NOT build prompts, choose between providers, apply guardrails,
 * or persist anything. Those belong to other layers.
 */
interface InferenceProvider
{
    /** Stable identifier used in metrics, circuit-breaker keys and logs (e.g. "claude"). */
    public function name(): string;

    /** A blocking completion. Throws ProviderException on transport/model failure. */
    public function complete(InferenceRequest $request): InferenceResponse;

    /**
     * A streaming completion. Yields text deltas as they arrive and RETURNS the final
     * InferenceResponse (text assembled, usage, latency) via the generator return value.
     *
     * @return \Generator<int,string,mixed,InferenceResponse>
     */
    public function stream(InferenceRequest $request): \Generator;

    /** Cheap liveness probe used by the gateway and /health endpoint. */
    public function health(): ProviderHealth;
}
