<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Expands a worshipper's mood (a chip label or free text like "I feel lonely")
 * into a set of spiritual theme tags used by MusicRecommendationService.
 *
 * Phase 1 is purely deterministic — a built-in dictionary plus an optional
 * admin override (the `music.mood_dictionary` setting, a JSON object of
 * mood => [tags]). No LLM is involved; unmatched free text falls back to its
 * own keyword tokens so a recommendation can still be produced.
 */
class MoodExpansionService
{
    /**
     * Built-in mood => theme-tag dictionary. Keys are matched case-insensitively
     * and emoji/labels from the UI map onto these canonical keys.
     */
    public const DICTIONARY = [
        'happy'        => ['joy', 'celebration', 'gratitude', 'praise', 'thanksgiving'],
        'joyful'       => ['joy', 'celebration', 'praise', 'thanksgiving'],
        'sad'          => ['comfort', 'hope', 'healing', 'grace'],
        'depression'   => ['hope', 'identity', 'love', 'restoration'],
        'anxiety'      => ['peace', 'trust', 'fear', 'protection', 'faith'],
        'anxious'      => ['peace', 'trust', 'fear', 'protection', 'faith'],
        'lonely'       => ['presence', 'companionship', 'friendship', 'jesus'],
        'broken heart' => ['healing', 'comfort', 'restoration', 'forgiveness'],
        'angry'        => ['peace', 'forgiveness', 'patience', 'surrender'],
        'tired'        => ['rest', 'renewal', 'strength', 'hope'],
        'peace'        => ['peace', 'rest', 'trust', 'stillness'],
        'repentance'   => ['forgiveness', 'grace', 'mercy', 'renewal'],
        'thankful'     => ['gratitude', 'thanksgiving', 'praise', 'joy'],
        'grateful'     => ['gratitude', 'thanksgiving', 'praise', 'joy'],
        'revival'      => ['fire', 'holy spirit', 'worship', 'surrender', 'praise'],
        'need prayer'  => ['intercession', 'faith', 'hope', 'surrender'],
        'grieving'     => ['comfort', 'hope', 'healing', 'grace'],
        'seeking'      => ['presence', 'faith', 'guidance', 'trust'],
        'hopeful'      => ['hope', 'faith', 'promise', 'future'],
    ];

    /**
     * Expand a mood string into a de-duplicated, lower-cased list of theme tags.
     * Always includes the normalized mood itself so an unmatched mood still
     * carries at least one usable tag.
     */
    public function expand(string $mood): array
    {
        $norm = $this->normalize($mood);
        if ($norm === '') {
            return [];
        }

        $dictionary = $this->dictionary();

        // 1. Exact key match (chip labels and canonical moods).
        if (isset($dictionary[$norm])) {
            return $this->finalize($norm, $dictionary[$norm]);
        }

        // 2. Free text ("i feel lonely and afraid"): collect tags from every
        //    dictionary key whose word appears in the input.
        $tags = [];
        foreach ($dictionary as $key => $keyTags) {
            if ($this->mentions($norm, $key)) {
                $tags = array_merge($tags, $keyTags, [$key]);
            }
        }

        return $this->finalize($norm, $tags);
    }

    /** Canonical mood keys, for the UI mood selector. */
    public function moodKeys(): array
    {
        return array_keys($this->dictionary());
    }

    /** Built-in dictionary merged with the optional admin JSON override. */
    private function dictionary(): array
    {
        $override = json_decode((string) Setting::get('music.mood_dictionary', ''), true);
        if (! is_array($override)) {
            return self::DICTIONARY;
        }

        // Override values must be arrays of strings; ignore malformed entries.
        $clean = [];
        foreach ($override as $key => $tags) {
            if (is_string($key) && is_array($tags)) {
                $clean[$this->normalize($key)] = array_values(array_filter(
                    array_map(fn ($t) => $this->normalize((string) $t), $tags),
                    fn ($t) => $t !== ''
                ));
            }
        }

        return array_merge(self::DICTIONARY, $clean);
    }

    /** Whether $haystack contains $needle as a whole word (or substring for multi-word keys). */
    private function mentions(string $haystack, string $needle): bool
    {
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
