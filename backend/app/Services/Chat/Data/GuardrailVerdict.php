<?php

namespace App\Services\Chat\Data;

/**
 * The decision returned by an input or output guardrail. The orchestrator acts ONLY on
 * this DTO — it never sees the guardrail's internal rules (those belong to the Guardrail
 * layer). `text` lets an OUTPUT guard return a sanitised replacement (e.g. username
 * stripped) while still allowing the response.
 */
final class GuardrailVerdict
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $text = null,
        public readonly ?string $reason = null,
        public readonly ?string $safeMessage = null,
    ) {}

    public static function allow(?string $text = null): self
    {
        return new self(true, $text);
    }

    /** Block the request; `safeMessage` is what the user sees instead of model output. */
    public static function block(string $reason, string $safeMessage): self
    {
        return new self(false, null, $reason, $safeMessage);
    }
}
