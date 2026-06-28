<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Expands a worshipper's mood into spiritual theme tags for the recommender.
 *
 * Moods are the six universal categories defined in config/worship_moods.php
 * (energy / feel_good / focus / love / relax / heartbreak) — that file is the
 * single source of truth for ids, translated labels, emoji, expansion concepts,
 * and free-text trigger words. This service only reads it.
 *
 * Expansion is deterministic (no LLM, Phase 1): an exact mood id returns its
 * concepts; free text ("I feel lonely and afraid") is mapped by scanning each
 * mood's trigger words and merging the concepts of every mood it touches. An
 * optional admin JSON override (`music.mood_dictionary`, mood-id => [concepts])
 * layers on top so concepts can be tuned without a deploy.
 */
class MoodExpansionService
{
    /** Native display label for a mood id in the given language. */
    public function label(string $mood, string $language): string
    {
        $norm = $this->normalize($mood);
        $mc   = $this->moods();

        if ($language === 'en' || $norm === '' || ! isset($mc[$norm])) {
            return ucwords($norm !== '' ? $norm : trim($mood));
        }

        return $mc[$norm]['labels'][$language] ?? ucwords($norm);
    }

    /** Per-language label map ({en, my, td}) for one mood id. */
    public function labels(string $mood): array
    {
        $norm   = $this->normalize($mood);
        $labels = $this->moods()[$norm]['labels'] ?? [];

        return [
            'en' => $labels['en'] ?? ucwords($norm),
            'my' => $labels['my'] ?? ($labels['en'] ?? ucwords($norm)),
            'td' => $labels['td'] ?? ($labels['en'] ?? ucwords($norm)),
        ];
    }

    /** Chip emoji for a mood id (🎵 fallback). */
    public function emoji(string $mood): string
    {
        return $this->moods()[$this->normalize($mood)]['emoji'] ?? '🎵';
    }

    /**
     * Expand a mood string into a de-duplicated, lower-cased list of concept
     * tags. Always includes the normalized mood itself so an unmatched mood
     * (or raw free text) still carries at least one usable tag.
     */
    public function expand(string $mood): array
    {
        $norm = $this->normalize($mood);
        if ($norm === '') {
            return [];
        }

        $concepts = $this->concepts();

        // 1. Exact mood id (a chip selection, e.g. "relax").
        if (isset($concepts[$norm])) {
            return $this->finalize($norm, $concepts[$norm]);
        }

        // 2. Free text ("i feel lonely and afraid") OR a legacy stored mood key
        //    (anxiety/peace/…): collect concepts from every mood whose trigger
        //    word appears in the input.
        $tags = [];
        foreach ($this->moods() as $id => $cfg) {
            foreach ((array) ($cfg['triggers'] ?? []) as $trigger) {
                if ($this->mentions($norm, $this->normalize((string) $trigger))) {
                    $tags = array_merge($tags, $concepts[$id] ?? []);
                    break;
                }
            }
        }

        return $this->finalize($norm, $tags);
    }

    /** Mood ids, in config order, for the UI selector. */
    public function moodKeys(): array
    {
        return array_keys($this->moods());
    }

    /**
     * Resolve a mood string to a canonical mood id, or null if it matches none.
     * Accepts an id ("relax"), a legacy stored key, or an exact trigger word
     * ("peace" => "relax"). Used by the JSON importer so older catalogs whose
     * moods predate the six-category collapse still import cleanly.
     */
    public function canonical(string $mood): ?string
    {
        $norm = $this->normalize($mood);
        if ($norm === '') {
            return null;
        }

        $moods = $this->moods();
        if (isset($moods[$norm])) {
            return $norm;
        }

        foreach ($moods as $id => $cfg) {
            foreach ((array) ($cfg['triggers'] ?? []) as $trigger) {
                if ($this->normalize((string) $trigger) === $norm) {
                    return $id;
                }
            }
        }

        return null;
    }

    /** Mood config table (config defaults; not affected by the concepts override). */
    private function moods(): array
    {
        return (array) config('worship_moods.moods', []);
    }

    /**
     * mood-id => [concepts], config defaults merged with the optional admin JSON
     * override (`music.mood_dictionary`). Override values must be arrays of
     * strings; malformed entries are ignored.
     */
    private function concepts(): array
    {
        $base = [];
        foreach ($this->moods() as $id => $cfg) {
            $base[$id] = array_map(fn ($t) => $this->normalize((string) $t), (array) ($cfg['concepts'] ?? []));
        }

        $override = json_decode((string) Setting::get('music.mood_dictionary', ''), true);
        if (! is_array($override)) {
            return $base;
        }

        $clean = [];
        foreach ($override as $key => $tags) {
            if (is_string($key) && is_array($tags)) {
                $clean[$this->normalize($key)] = array_values(array_filter(
                    array_map(fn ($t) => $this->normalize((string) $t), $tags),
                    fn ($t) => $t !== ''
                ));
            }
        }

        return array_merge($base, $clean);
    }

    /** Whether $haystack contains $needle as a whole word (or substring for multi-word triggers). */
    private function mentions(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        if (str_contains($needle, ' ')) {
            return str_contains($haystack, $needle);
        }

        return (bool) preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $haystack);
    }

    /** Merge mood + tags, lower-case, de-duplicate, drop empties. */
    private function finalize(string $mood, array $tags): array
    {
        $all = array_merge([$mood], $tags);
        $all = array_map(fn ($t) => $this->normalize((string) $t), $all);

        return array_values(array_unique(array_filter($all, fn ($t) => $t !== '')));
    }

    /** Lower-case, collapse whitespace, strip surrounding punctuation. */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value, " \t\n\r\0\x0B.,!?;:");
    }
}
