<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;

/**
 * Pre-inference safety gate (crisis, prompt-injection, PII, validation). The orchestrator
 * calls this BEFORE retrieving knowledge or spending an inference call, and short-circuits
 * with a safe message on a block. The rules live in the Guardrail layer — this interface
 * is the seam the orchestrator depends on.
 */
interface InputGuardrail
{
    public function inspect(ChatContext $context): GuardrailVerdict;
}
