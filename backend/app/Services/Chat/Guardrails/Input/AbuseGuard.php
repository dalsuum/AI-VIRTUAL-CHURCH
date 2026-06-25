<?php

namespace App\Services\Chat\Guardrails\Input;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\InputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * Blocks abusive / hateful input using a policy-defined term list. Deliberately simple and
 * fast (a pre-LLM gate). Sophisticated moderation belongs to the output side; this is the
 * cheap front-door filter that keeps clearly abusive prompts from ever reaching a model.
 */
final class AbuseGuard implements InputGuard
{
    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'abuse';
    }

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        $policy = $this->policies->get('abuse', ['terms' => []]);
        $haystack = mb_strtolower($context->request->message);

        foreach ((array) ($policy['terms'] ?? []) as $term) {
            if (preg_match('/\b' . preg_quote(mb_strtolower((string) $term), '/') . '\b/u', $haystack) === 1) {
                return GuardrailVerdict::block(
                    'abuse',
                    (string) ($policy['safe_message'] ?? 'Let us keep this space respectful. How can I help you today?'),
                );
            }
        }

        return GuardrailVerdict::allow();
    }
}
