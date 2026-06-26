<?php

namespace App\Services\Inference;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A per-provider circuit breaker backed by the shared cache (Redis in prod), so the
 * OPEN state is visible across every web node and queue worker — a provider that has
 * started failing is skipped fleet-wide, not just in one process.
 *
 * States:
 *   CLOSED    — calls flow; consecutive failures are counted.
 *   OPEN      — calls fast-fail for `cooldown` seconds (no network cost).
 *   HALF_OPEN — after cooldown, ONE trial call is allowed; success closes the circuit,
 *               failure re-opens it.
 *
 * This class makes no network calls and knows nothing about providers beyond a name —
 * it is pure resilience policy, fully unit-testable with an array cache.
 */
class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 30,
    ) {}

    /** Whether a call to this provider may proceed right now. */
    public function allows(string $provider): bool
    {
        return $this->state($provider) !== self::OPEN;
    }

    public function state(string $provider): string
    {
        $openedAt = $this->cache->get($this->key($provider, 'opened_at'));

        if ($openedAt === null) {
            return self::CLOSED;
        }

        if ((time() - (int) $openedAt) >= $this->cooldownSeconds) {
            return self::HALF_OPEN;
        }

        return self::OPEN;
    }

    /** Record a successful call: clears failures and closes the circuit. */
    public function recordSuccess(string $provider): void
    {
        $this->cache->forget($this->key($provider, 'failures'));
        $this->cache->forget($this->key($provider, 'opened_at'));
    }

    /** Record a failed call: increments failures and opens the circuit at threshold. */
    public function recordFailure(string $provider): void
    {
        // A failure during HALF_OPEN trips straight back to OPEN.
        if ($this->state($provider) === self::HALF_OPEN) {
            $this->open($provider);

            return;
        }

        $failures = (int) $this->cache->get($this->key($provider, 'failures'), 0) + 1;
        $this->cache->put($this->key($provider, 'failures'), $failures, $this->ttl());

        if ($failures >= $this->failureThreshold) {
            $this->open($provider);
        }
    }

    private function open(string $provider): void
    {
        $this->cache->put($this->key($provider, 'opened_at'), time(), $this->ttl());
    }

    /** Persistence TTL for breaker state — always outlives the cooldown window. */
    private function ttl(): int
    {
        return max($this->cooldownSeconds * 4, 60);
    }

    private function key(string $provider, string $suffix): string
    {
        return "inference:cb:{$provider}:{$suffix}";
    }
}
