<?php

namespace App\Services\Chat\Support;

use App\Services\Chat\Contracts\LanguageDetector;

/**
 * Offline, dependency-free language detection good enough to route the project's
 * supported chat languages. A user/session hint always wins. Without a hint we use
 * strong Unicode script signals first, then small Latin-language stop-word sets for
 * world languages; ambiguous Latin still falls back to English. Tedim is written in
 * Latin script, so it remains hint-driven rather than guessed.
 */
final class HeuristicLanguageDetector implements LanguageDetector
{
    /** @param list<string>|null $supported */
    public function __construct(private readonly ?array $supported = null) {}

    public function detect(string $text, ?string $hint = null): string
    {
        $supported = $this->supportedCodes();
        $hint = $this->normalizeCode($hint);

        if ($hint !== null && in_array($hint, $supported, true)) {
            return $hint;
        }

        // Myanmar Unicode block U+1000–U+109F.
        if (preg_match('/[\x{1000}-\x{109F}]/u', $text) === 1) {
            return 'my';
        }

        $latin = $this->latinHeuristic($text);
        if ($latin !== null && in_array($latin, $supported, true)) {
            return $latin;
        }

        return 'en';
    }

    /** @return list<string> */
    private function supportedCodes(): array
    {
        $codes = $this->supported;
        if ($codes === null) {
            $codes = array_keys((array) config('languages.list', ['en' => []]));
            $codes[] = 'td';
        }

        $expanded = [];
        foreach ($codes as $code) {
            $norm = $this->normalizeCode((string) $code);
            if ($norm !== null) {
                $expanded[] = $norm;
            }
        }

        return array_values(array_unique($expanded ?: ['en']));
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        return strtolower(strtok($code, '-'));
    }

    private function latinHeuristic(string $text): ?string
    {
        $lower = ' ' . mb_strtolower($text) . ' ';
        $scores = [];
        foreach ($this->latinSignals() as $code => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<!\pL)' . preg_quote($word, '/') . '(?!\pL)/u', $lower) === 1) {
                    $scores[$code] = ($scores[$code] ?? 0) + 1;
                }
            }
        }

        arsort($scores);
        $code = array_key_first($scores);

        return ($code !== null && ($scores[$code] ?? 0) >= 2) ? $code : null;
    }

    /** @return array<string,list<string>> */
    private function latinSignals(): array
    {
        return [
            'fr' => ['bonjour', 'merci', 'prière', 'prier', 'dieu', 'jésus', 'seigneur', 'église', 'foi', 'paix', 'amour', 'pourquoi', 'comment'],
            'de' => ['hallo', 'danke', 'gebet', 'beten', 'gott', 'jesus', 'herr', 'kirche', 'glaube', 'frieden', 'liebe', 'warum', 'wie'],
            'es' => ['hola', 'gracias', 'oración', 'orar', 'dios', 'jesús', 'señor', 'iglesia', 'fe', 'paz', 'amor', 'por qué', 'cómo'],
        ];
    }
}
