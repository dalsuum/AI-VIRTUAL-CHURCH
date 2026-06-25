<?php

namespace Tests\Unit\Inference;

use App\Services\Inference\CircuitBreaker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Pure-policy tests for the circuit breaker — no DB, no network. Uses an array cache so
 * the three-state machine (closed → open → half-open → closed/open) is verified in
 * isolation.
 */
class CircuitBreakerTest extends TestCase
{
    private function breaker(int $threshold = 3, int $cooldown = 30): array
    {
        $cache = new Repository(new ArrayStore());

        return [new CircuitBreaker($cache, $threshold, $cooldown), $cache];
    }

    public function test_starts_closed_and_allows(): void
    {
        [$cb] = $this->breaker();

        $this->assertSame(CircuitBreaker::CLOSED, $cb->state('claude'));
        $this->assertTrue($cb->allows('claude'));
    }

    public function test_opens_after_threshold_failures(): void
    {
        [$cb] = $this->breaker(threshold: 3);

        $cb->recordFailure('claude');
        $cb->recordFailure('claude');
        $this->assertTrue($cb->allows('claude'), 'below threshold stays closed');

        $cb->recordFailure('claude');
        $this->assertSame(CircuitBreaker::OPEN, $cb->state('claude'));
        $this->assertFalse($cb->allows('claude'));
    }

    public function test_success_resets_failure_count(): void
    {
        [$cb] = $this->breaker(threshold: 3);

        $cb->recordFailure('claude');
        $cb->recordFailure('claude');
        $cb->recordSuccess('claude');
        $cb->recordFailure('claude');
        $cb->recordFailure('claude');

        $this->assertTrue($cb->allows('claude'), 'success cleared the prior failures');
    }

    public function test_transitions_to_half_open_after_cooldown(): void
    {
        // cooldown 0 ⇒ an opened circuit is immediately eligible for a trial call.
        [$cb] = $this->breaker(threshold: 1, cooldown: 0);

        $cb->recordFailure('claude');
        $this->assertSame(CircuitBreaker::HALF_OPEN, $cb->state('claude'));
        $this->assertTrue($cb->allows('claude'), 'half-open permits a trial');
    }

    public function test_is_isolated_per_provider(): void
    {
        [$cb] = $this->breaker(threshold: 1);

        $cb->recordFailure('claude');

        $this->assertFalse($cb->allows('claude'));
        $this->assertTrue($cb->allows('ollama_tedim'), 'one provider tripping must not affect another');
    }
}
