<?php

namespace App\Services\Inference\Data;

/**
 * The result of a non-streamed inference call. For streaming, providers yield text
 * chunks and emit a final InferenceResponse via the stream's return value.
 */
final class InferenceResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $providerName,
        public readonly string $model,
        public readonly TokenUsage $usage,
        public readonly int $latencyMs,
        public readonly ?string $finishReason = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'text'          => $this->text,
            'provider'      => $this->providerName,
            'model'         => $this->model,
            'usage'         => $this->usage->toArray(),
            'latency_ms'    => $this->latencyMs,
            'finish_reason' => $this->finishReason,
        ];
    }
}
