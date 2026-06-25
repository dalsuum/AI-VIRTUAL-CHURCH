<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * Grounding check: rather than asking "is this hallucinated?", it VERIFIES the answer
 * against the retrieved knowledge context (the reliable approach). It activates only when
 * the turn actually used knowledge AND retrieved something — for surfaces with no retrieval
 * it is a deliberate no-op, so it is correct today and gains teeth the moment RAG lands.
 *
 * Heuristic: measure lexical overlap between the answer and the retrieved snippets; below
 * the policy threshold the answer is poorly grounded. The policy 'action' decides
 * flag (allow) vs block. A future LLM/NLI verifier can replace the heuristic behind this
 * same interface without touching the pipeline.
 */
final class HallucinationGuard implements OutputGuard
{
    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'hallucination';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        $policy = $this->policies->get('hallucination', ['min_overlap' => 0.12, 'action' => 'flag', 'on_failure' => 'flag']);

        // Distinguish "no relevant knowledge" from "retrieval is broken". For a knowledge-backed
        // surface, a fail-closed policy refuses rather than answering confidently ungrounded.
        if ($context->capability->usesKnowledge() && $context->knowledge->failedToRetrieve()
            && ($policy['on_failure'] ?? 'flag') === 'block') {
            return GuardrailVerdict::block(
                'knowledge_unavailable',
                (string) ($policy['failure_message'] ?? 'I cannot reach my study references right now, so I would rather not answer that inaccurately. Please try again shortly.'),
            );
        }

        if (! $context->capability->usesKnowledge() || $context->knowledge->isEmpty()) {
            return GuardrailVerdict::allow($text); // nothing to verify against
        }

        $overlap = $this->overlap($text, $this->corpus($context));

        if ($overlap < (float) ($policy['min_overlap'] ?? 0.12)) {
            if (($policy['action'] ?? 'flag') === 'block') {
                return GuardrailVerdict::block(
                    'hallucination',
                    (string) ($policy['safe_message'] ?? 'I am not certain enough from the available sources to answer that reliably.'),
                );
            }
            // flagged-only: allow, telemetry captures the verdict path.
        }

        return GuardrailVerdict::allow($text);
    }

    private function corpus(ChatContext $context): string
    {
        return implode(' ', array_map(
            static fn (array $s) => $s['text'],
            $context->knowledge->snippets,
        ));
    }

    /** Jaccard-style overlap of answer tokens that appear in the source corpus. */
    private function overlap(string $answer, string $source): float
    {
        $answerTokens = $this->tokens($answer);
        if ($answerTokens === []) {
            return 1.0;
        }
        $sourceTokens = array_flip($this->tokens($source));

        $hits = 0;
        foreach ($answerTokens as $token) {
            if (isset($sourceTokens[$token])) {
                $hits++;
            }
        }

        return $hits / count($answerTokens);
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $m);

        return array_values(array_unique($m[0] ?? []));
    }
}
