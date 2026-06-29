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
        $hint = $this->canonicalCode($hint, $supported);

        if ($hint !== null && in_array($hint, $supported, true)) {
            return $hint;
        }

        // Myanmar Unicode block U+1000–U+109F.
        if (preg_match('/[\x{1000}-\x{109F}]/u', $text) === 1) {
            return 'my';
        }

        if (preg_match('/[\x{0590}-\x{05FF}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('he', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('ar', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{0900}-\x{097F}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('hi', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{0B80}-\x{0BFF}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('ta', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('th', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{3040}-\x{30FF}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('ja', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('ko', $supported)) !== null) {
            return $code;
        }

        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text) === 1
            && ($code = $this->supportedCodeFor('zh-CN', $supported)) !== null) {
            return $code;
        }

        $latin = $this->latinHeuristic($text);
        if ($latin !== null && ($latin = $this->supportedCodeFor($latin, $supported)) !== null) {
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
            $norm = $this->normalizeCanonical((string) $code);
            if ($norm !== null) {
                $expanded[] = $norm;
            }
        }

        return array_values(array_unique($expanded ?: ['en']));
    }

    /** @param list<string> $supported */
    private function supportedCodeFor(string $candidate, array $supported): ?string
    {
        $code = $this->canonicalCode($candidate, $supported);

        return $code !== null && in_array($code, $supported, true) ? $code : null;
    }

    /** @param list<string> $supported */
    private function canonicalCode(?string $code, array $supported): ?string
    {
        $norm = $this->normalizeCanonical($code);
        if ($norm === null) {
            return null;
        }

        foreach ($supported as $known) {
            if (strcasecmp($known, $norm) === 0) {
                return $known;
            }
        }

        $base = strtolower(strtok($norm, '-'));
        foreach ($supported as $known) {
            if (strtolower(strtok($known, '-')) === $base) {
                return $known;
            }
        }

        return $norm;
    }

    private function normalizeCanonical(?string $code): ?string
    {
        $code = str_replace('_', '-', trim((string) $code));
        if ($code === '') {
            return null;
        }

        $parts = explode('-', $code, 2);
        $base = strtolower($parts[0]);

        return isset($parts[1]) && $parts[1] !== ''
            ? $base . '-' . strtoupper($parts[1])
            : $base;
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
