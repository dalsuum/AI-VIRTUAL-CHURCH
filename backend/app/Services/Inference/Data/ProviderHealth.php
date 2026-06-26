<?php

namespace App\Services\Inference\Data;

/**
 * Point-in-time health of a provider, surfaced by /health checks and used by the
 * gateway to skip known-down providers before paying for a failed call.
 */
final class ProviderHealth
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $healthy,
        public readonly ?int $latencyMs = null,
        public readonly ?string $detail = null,
    ) {}

    public static function up(string $provider, int $latencyMs): self
    {
        return new self($provider, true, $latencyMs);
    }

    public static function down(string $provider, string $detail): self
    {
        return new self($provider, false, null, $detail);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider'   => $this->provider,
            'healthy'    => $this->healthy,
            'latency_ms' => $this->latencyMs,
            'detail'     => $this->detail,
        ];
    }
}
