<?php

namespace App\Services\Chat\Support;

use App\Services\Chat\Contracts\OutputGuardrail;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;

/**
 * Default output guardrail: enforces the project-wide no-username policy on generated
 * text for every language. If the model ever addresses the worshipper by their account
 * name, that token is stripped before persistence/return. This is a REAL policy guard,
 * not a placeholder — when the full Output Guardrail layer (moderation, hallucination
 * checks) ships, this becomes one guard inside a composite via the same interface.
 */
final class UsernameSanitizingOutputGuardrail implements OutputGuardrail
{
    public function review(string $modelOutput, ChatContext $context): GuardrailVerdict
    {
        $name = trim((string) ($context->request->user->name ?? ''));
        if ($name === '') {
            return GuardrailVerdict::allow($modelOutput);
        }

        // Strip the user's name and any leading vocative comma it leaves behind.
        $cleaned = preg_replace(
            '/\b' . preg_quote($name, '/') . '\b[,!]?\s*/iu',
            '',
            $modelOutput,
        );

        return GuardrailVerdict::allow($cleaned ?? $modelOutput);
    }
}
