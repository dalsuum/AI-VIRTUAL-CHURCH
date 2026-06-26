<?php

namespace App\Services\Chat\Support;

use App\Services\Chat\Contracts\LanguageDetector;

/**
 * Offline, dependency-free language detection good enough to route between the project's
 * supported languages (English, Myanmar/Burmese, Tedim). A user/session hint always wins;
 * otherwise we use Unicode script signals: Myanmar script ⇒ 'my'; Latin defaults to 'en'.
 * Tedim is written in Latin script, so it cannot be distinguished from English by script
 * alone — it must come from an explicit hint (session language), which is exactly how the
 * existing modules set it. This keeps detection honest rather than guessing wrongly.
 */
final class HeuristicLanguageDetector implements LanguageDetector
{
    /** @param list<string> $supported */
    public function __construct(private readonly array $supported = ['en', 'my', 'td']) {}

    public function detect(string $text, ?string $hint = null): string
    {
        if ($hint !== null && in_array($hint, $this->supported, true)) {
            return $hint;
        }

        // Myanmar Unicode block U+1000–U+109F.
        if (preg_match('/[\x{1000}-\x{109F}]/u', $text) === 1) {
            return 'my';
        }

        return 'en';
    }
}
