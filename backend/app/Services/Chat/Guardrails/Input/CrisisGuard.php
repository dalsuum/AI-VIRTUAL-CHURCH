<?php

namespace App\Services\Chat\Guardrails\Input;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\InputGuard;
use App\Services\CrisisInterceptService;

/**
 * Highest-priority input guard. Delegates to the existing, already-tested
 * CrisisInterceptService (keyword interception + audit logging), exposing it as one link
 * in the chain. Reuse over reimplementation — the proven crisis logic is unchanged; only
 * its invocation moves behind the InputGuard contract.
 */
final class CrisisGuard implements InputGuard
{
    public function __construct(private readonly CrisisInterceptService $crisis) {}

    public function key(): string
    {
        return 'crisis';
    }

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        $token = $context->request->sessionToken ?? $context->session->id;
        $result = $this->crisis->inspect($token, $context->request->message);

        return ($result['intercepted'] ?? false) === true
            ? GuardrailVerdict::block('crisis', (string) $result['resource'])
            : GuardrailVerdict::allow();
    }
}
