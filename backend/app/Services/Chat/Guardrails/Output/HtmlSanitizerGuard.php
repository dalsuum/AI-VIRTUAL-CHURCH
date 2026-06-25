<?php

namespace App\Services\Chat\Guardrails\Output;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\OutputGuard;

/**
 * Strips raw HTML from model output so a generated <script>/<img onerror> can never reach
 * the Vue client as markup (OWASP LLM02 insecure output handling / stored XSS). Runs early
 * in the output chain so later guards see clean text. Allows-with-replacement; never blocks.
 */
final class HtmlSanitizerGuard implements OutputGuard
{
    public function key(): string
    {
        return 'html_sanitizer';
    }

    public function inspect(ChatContext $context, string $text): GuardrailVerdict
    {
        // Remove tags entirely, then decode entities so legitimate punctuation survives.
        $stripped = strip_tags($text);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return GuardrailVerdict::allow($decoded);
    }
}
