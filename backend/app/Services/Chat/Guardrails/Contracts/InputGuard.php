<?php

namespace App\Services\Chat\Guardrails\Contracts;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;

/**
 * One link in the input guard chain (Chain of Responsibility). A guard inspects the
 * pending turn and returns a verdict: allow to continue, or block with a safe message.
 *
 * Guards know NOTHING about controllers, HTTP requests, or inference providers — they
 * receive only the ChatContext and consult policy via an injected PolicyRepository.
 * Ordering, priority and per-capability enable/disable are the pipeline's concern, not
 * the guard's, so each guard stays small and single-purpose (SRP).
 */
interface InputGuard
{
    /** Stable key used for ordering and per-capability enable/disable in config. */
    public function key(): string;

    public function inspect(ChatContext $context): GuardrailVerdict;
}
