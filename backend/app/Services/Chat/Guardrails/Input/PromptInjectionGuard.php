<?php

namespace App\Services\Chat\Guardrails\Input;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\InputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;

/**
 * Detects direct prompt-injection / jailbreak attempts in the user message ("ignore
 * previous instructions", "you are now DAN", system-prompt exfiltration, etc.). Patterns
 * live in the 'injection' policy, NOT in this class, so the rule set evolves without code
 * changes (OWASP LLM01).
 *
 * This guards DIRECT injection from user text; INDIRECT injection via retrieved documents
 * is handled structurally by the Prompt Builder (data/instruction separation).
 */
final class PromptInjectionGuard implements InputGuard
{
    public function __construct(private readonly PolicyRepository $policies) {}

    public function key(): string
    {
        return 'prompt_injection';
    }

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        $policy = $this->policies->get('injection', ['patterns' => [], 'safe_message' => '']);
        $text = mb_strtolower($context->request->message);

        foreach ((array) ($policy['patterns'] ?? []) as $pattern) {
            if (@preg_match($pattern, '') !== false) {
                // Treat the policy entry as a full regex when it compiles as one…
                if (preg_match($pattern, $text) === 1) {
                    return GuardrailVerdict::block('prompt_injection', $this->safe($policy));
                }
            } elseif (str_contains($text, mb_strtolower((string) $pattern))) {
                // …otherwise treat it as a literal phrase.
                return GuardrailVerdict::block('prompt_injection', $this->safe($policy));
            }
        }

        return GuardrailVerdict::allow();
    }

    /** @param array<string,mixed> $policy */
    private function safe(array $policy): string
    {
        return (string) ($policy['safe_message']
            ?? 'I can only help with prayer, scripture and encouragement. How can I support you today?');
    }
}
