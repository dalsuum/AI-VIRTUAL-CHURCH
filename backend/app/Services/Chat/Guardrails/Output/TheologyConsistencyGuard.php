<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * Domain-specific guard distinct from generic hallucination checks: it enforces ministry
 * expectations on teaching. Driven entirely by the 'theology' policy so each church can
 * tune it. Current rule set:
 *   • forbidden_phrases — claims the platform must never make (configurable per tradition);
 *   • require_citation_markers — when the answer makes a definitive doctrinal claim
 *     ("the Bible says", "scripture teaches") it should accompany it with a verse reference,
 *     otherwise the turn is flagged/blocked per policy action.
 *
 * Like the other domain guards, it stays conservative (default 'flag') and improves as RAG
 * and translation-pinning land.
 */
final class TheologyConsistencyGuard implements OutputGuard
{
    private const REF = '/\b(?:[1-3]\s?)?[A-Z][a-z]+\s+\d{1,3}:\d{1,3}\b/';

    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'theology';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        $policy = $this->policies->get('theology', [
            'forbidden_phrases'        => [],
            'require_citation_markers' => [],
            'action'                   => 'flag',
        ]);
        $lower = mb_strtolower($text);

        foreach ((array) ($policy['forbidden_phrases'] ?? []) as $phrase) {
            if (str_contains($lower, mb_strtolower((string) $phrase))) {
                return GuardrailVerdict::block(
                    'theology',
                    (string) ($policy['safe_message'] ?? 'Let me reconsider that and stay close to scripture.'),
                );
            }
        }

        // A definitive doctrinal claim without any verse reference is poorly supported.
        $makesClaim = false;
        foreach ((array) ($policy['require_citation_markers'] ?? []) as $marker) {
            if (str_contains($lower, mb_strtolower((string) $marker))) {
                $makesClaim = true;
                break;
            }
        }
        if ($makesClaim && preg_match(self::REF, $text) !== 1 && ($policy['action'] ?? 'flag') === 'block') {
            return GuardrailVerdict::block(
                'theology_uncited',
                (string) ($policy['safe_message'] ?? 'When sharing what scripture teaches, I should point to the specific verse. Let me do that.'),
            );
        }

        return GuardrailVerdict::allow($text);
    }
}
