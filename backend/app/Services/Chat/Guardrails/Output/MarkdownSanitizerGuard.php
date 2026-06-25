<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;

/**
 * Neutralises dangerous markdown — links with javascript:/data: schemes and embedded
 * images — that could smuggle script or exfiltrate via auto-loaded URLs when rendered.
 * Plain emphasis/lists are preserved; only the risky constructs are defanged. Allows with
 * replacement; never blocks.
 */
final class MarkdownSanitizerGuard implements OutputGuard
{
    public function key(): string
    {
        return 'markdown_sanitizer';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        // Drop image embeds: ![alt](url) → alt
        $clean = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;

        // Defuse links with unsafe schemes: [label](javascript:…) → label
        $clean = preg_replace(
            '/\[([^\]]+)\]\(\s*(?:javascript|data|vbscript):[^)]*\)/i',
            '$1',
            $clean,
        ) ?? $clean;

        return GuardrailVerdict::allow($clean);
    }
}
