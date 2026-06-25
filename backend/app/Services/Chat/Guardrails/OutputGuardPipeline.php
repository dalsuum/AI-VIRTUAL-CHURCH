<?php

namespace App\Services\Chat\Guardrails;

use App\Services\Chat\Contracts\OutputGuardrail;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;

/**
 * Runs the output guards in configured order, THREADING the working text through each so
 * sanitisers compose (HTML strip → markdown strip → username scrub), while validators
 * (moderation, hallucination, citation, theology) may BLOCK and short-circuit.
 *
 * Implements the orchestrator's existing OutputGuardrail seam: it returns a single final
 * verdict carrying the fully-sanitised text, so the orchestrator stays unchanged.
 */
final class OutputGuardPipeline implements OutputGuardrail
{
    /** @param iterable<OutputGuard> $guards */
    public function __construct(
        private readonly iterable $guards,
        private readonly GuardChainResolver $resolver,
    ) {}

    public function review(string $modelOutput, ChatContext $context): GuardrailVerdict
    {
        $text = $modelOutput;

        foreach ($this->resolver->order('output', $context->capability->key(), $this->guards) as $guard) {
            $verdict = $guard->inspect($context, $text);
            if (! $verdict->allowed) {
                return $verdict; // a blocking validator stops the chain
            }
            // A sanitiser may return replacement text; carry it to the next guard.
            $text = $verdict->text ?? $text;
        }

        return GuardrailVerdict::allow($text);
    }
}
