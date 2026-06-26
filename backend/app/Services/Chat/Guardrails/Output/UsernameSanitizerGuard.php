<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;

/**
 * Enforces the project-wide no-username policy on generated text for every language: if the
 * model addresses the worshipper by their account name, that token (and any vocative comma)
 * is removed before the text is persisted or returned. Allows with replacement; never blocks.
 */
final class UsernameSanitizerGuard implements OutputGuard
{
    public function key(): string
    {
        return 'username_sanitizer';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        $name = trim((string) ($context->request->user->name ?? ''));
        if ($name === '') {
            return GuardrailVerdict::allow($text);
        }

        $cleaned = preg_replace('/\b' . preg_quote($name, '/') . '\b[,!]?\s*/iu', '', $text);

        return GuardrailVerdict::allow($cleaned ?? $text);
    }
}
