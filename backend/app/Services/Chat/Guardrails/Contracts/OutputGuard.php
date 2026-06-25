<?php

namespace App\Services\Chat\Guardrails\Contracts;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;

/**
 * One link in the output guard chain. Receives the CURRENT working text (which an earlier
 * sanitiser may already have modified) plus the ChatContext — from which a guard can read
 * the retrieved knowledge ($context->knowledge) and the raw inference result
 * ($context->inference). Returns:
 *   • allow($text) to pass through, optionally replacing the text (sanitisers), or
 *   • block(reason, safeMessage) to stop and return a safe response.
 *
 * Like InputGuard, it never touches controllers/HTTP/providers and reads rules from the
 * injected PolicyRepository.
 */
interface OutputGuard
{
    public function key(): string;

    public function inspect(ChatContext $context, string $text): GuardrailVerdict;
}
