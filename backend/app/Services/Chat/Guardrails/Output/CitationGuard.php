<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * For knowledge-backed surfaces (Bible Study), ensures any scripture reference the answer
 * cites (e.g. "John 3:16") actually appears in the retrieved context — catching invented or
 * misattributed citations. No-op when the turn used no knowledge. Default action 'flag'
 * (allow) so it observes before it enforces; switch to 'block' once retrieval coverage is
 * trusted.
 */
final class CitationGuard implements OutputGuard
{
    /** Matches "Book Chapter:Verse" references, incl. numbered books (1 John 4:8). */
    private const REF = '/\b(?:[1-3]\s?)?[A-Z][a-z]+\s+\d{1,3}:\d{1,3}\b/';

    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'citation';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        if (! $context->capability->usesKnowledge() || $context->knowledge->isEmpty()) {
            return GuardrailVerdict::allow($text);
        }

        preg_match_all(self::REF, $text, $matches);
        $refs = $matches[0] ?? [];
        if ($refs === []) {
            return GuardrailVerdict::allow($text);
        }

        $corpus = implode(' ', array_map(static fn (array $s) => $s['text'], $context->knowledge->snippets));
        $policy = $this->policies->get('citation', ['action' => 'flag']);

        foreach ($refs as $ref) {
            if (! str_contains($corpus, $ref)) {
                if (($policy['action'] ?? 'flag') === 'block') {
                    return GuardrailVerdict::block(
                        'citation',
                        (string) ($policy['safe_message'] ?? 'I want to be accurate with scripture — let me ground that answer in the verses we have before citing them.'),
                    );
                }
                break; // flagged-only
            }
        }

        return GuardrailVerdict::allow($text);
    }
}
