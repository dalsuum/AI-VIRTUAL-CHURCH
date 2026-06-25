<?php

namespace App\Services\Chat\Support;

/**
 * A monotonic wall-clock deadline for one orchestration. Checked between steps so a turn
 * cannot run unbounded even if a downstream timeout is misconfigured. Provider-level HTTP
 * timeouts remain the hard stop for a single inference call; this is the orchestration
 * envelope around the whole pipeline.
 */
final class Deadline
{
    private function __construct(private readonly float $expiresAt) {}

    public static function in(int $seconds): self
    {
        return new self(microtime(true) + $seconds);
    }

    public function exceeded(): bool
    {
        return microtime(true) >= $this->expiresAt;
    }

    public function remainingSeconds(): int
    {
        return max(0, (int) ceil($this->expiresAt - microtime(true)));
    }
}
