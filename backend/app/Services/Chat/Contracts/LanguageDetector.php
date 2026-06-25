<?php

namespace App\Services\Chat\Contracts;

/**
 * Detects the conversation language so the orchestrator can route to the right model
 * chain (inference layer) and persona. An explicit user/session hint always wins; the
 * detector only resolves the ambiguous case. Implementations must be cheap and offline.
 */
interface LanguageDetector
{
    /** Return a language code (e.g. 'en', 'my', 'td'); $hint short-circuits when set. */
    public function detect(string $text, ?string $hint = null): string;
}
