<?php

namespace App\Services\Chat\Support;

use App\Services\Chat\Contracts\InputGuardrail;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\CrisisInterceptService;

/**
 * Default input guardrail: adapts the existing CrisisInterceptService to the orchestrator's
 * InputGuardrail seam, so the proven crisis-keyword interception is reused rather than
 * reimplemented. When the dedicated Guardrail layer lands (prompt-injection, PII, …), this
 * binding is swapped for a composite that runs several guards — no orchestrator change.
 */
final class CrisisInputGuardrail implements InputGuardrail
{
    public function __construct(private readonly CrisisInterceptService $crisis) {}

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        $token = $context->request->sessionToken ?? $context->session->id;
        $result = $this->crisis->inspect($token, $context->request->message);

        if (($result['intercepted'] ?? false) === true) {
            return GuardrailVerdict::block('crisis', (string) $result['resource']);
        }

        return GuardrailVerdict::allow();
    }
}
