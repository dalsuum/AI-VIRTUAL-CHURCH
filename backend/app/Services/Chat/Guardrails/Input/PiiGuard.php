<?php

namespace App\Services\Chat\Guardrails\Input;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\InputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * Detects sensitive PII (credit cards, national IDs, emails…) the worshipper may share by
 * mistake. The policy decides the ACTION: 'block' (refuse and advise) or 'flag' (allow but
 * mark for telemetry). Default is 'flag' so we never silently swallow a pastoral message —
 * a ministry product errs toward listening, while still surfacing the risk.
 */
final class PiiGuard implements InputGuard
{
    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'pii';
    }

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        $policy = $this->policies->get('pii', ['patterns' => [], 'action' => 'flag']);

        foreach ((array) ($policy['patterns'] ?? []) as $pattern) {
            if (preg_match($pattern, $context->request->message) === 1) {
                if (($policy['action'] ?? 'flag') === 'block') {
                    return GuardrailVerdict::block(
                        'pii',
                        (string) ($policy['safe_message']
                            ?? 'For your safety, please avoid sharing personal details like card or ID numbers here.'),
                    );
                }
                break; // flagged only; allow through (telemetry records the 'pii' verdict path)
            }
        }

        return GuardrailVerdict::allow();
    }
}
