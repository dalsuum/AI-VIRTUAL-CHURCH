<?php

namespace App\Services\Chat\Guardrails;

use App\Services\Chat\Contracts\InputGuardrail;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\InputGuard;

/**
 * Runs the input guards in configured order and SHORT-CIRCUITS on the first block. It
 * implements the orchestrator's existing InputGuardrail seam, so swapping the single
 * default guard for this whole chain is a one-line binding change and the orchestrator is
 * untouched (Open/Closed; stable interface).
 *
 * Ordering and per-capability enable/disable come from config/guardrails.php via
 * GuardChainResolver — never hard-coded here — so guards can be reordered or disabled per
 * surface (e.g. drop scripture validation for Prayer) without code changes.
 */
final class InputGuardPipeline implements InputGuardrail
{
    /** @param iterable<InputGuard> $guards */
    public function __construct(
        private readonly iterable $guards,
        private readonly GuardChainResolver $resolver,
    ) {}

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        foreach ($this->resolver->order('input', $context->capability->key(), $this->guards) as $guard) {
            $verdict = $guard->inspect($context);
            if (! $verdict->allowed) {
                return $verdict; // first block wins; remaining guards are skipped
            }
        }

        return GuardrailVerdict::allow();
    }
}
