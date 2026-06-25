<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * Blocks model output that violates the content policy (banned terms / disallowed topics)
 * before it ever reaches the worshipper. Term list lives in the 'moderation' policy. A
 * blocked response is replaced with a safe pastoral fallback rather than surfacing the
 * offending text.
 */
final class ContentModerationGuard implements OutputGuard
{
    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'content_moderation';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        $policy = $this->policies->get('moderation', ['terms' => []]);
        $haystack = mb_strtolower($text);

        foreach ((array) ($policy['terms'] ?? []) as $term) {
            if (str_contains($haystack, mb_strtolower((string) $term))) {
                return GuardrailVerdict::block(
                    'content_moderation',
                    (string) ($policy['safe_message'] ?? 'I am sorry, I cannot help with that. Is there something else I can pray with you about?'),
                );
            }
        }

        return GuardrailVerdict::allow($text);
    }
}
