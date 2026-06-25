<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\WorshipTrack;

/**
 * Deterministic worship-song recommender — the "Music Recommendation Agent".
 *
 * No LLM in Phase 1: it expands the mood via MoodExpansionService, scores every
 * active track with the spec's weighted formula, applies the recent-track
 * no-repeat exclusion and artist diversity, then returns a 5–10 song playlist
 * plus a human-readable reason.
 *
 * Score weights (sum = 1.0):
 *   language    0.40  (exact language match)
 *   mood        0.30  (overlap of track.moods with the requested mood)
 *   theme       0.20  (overlap of track.themes with the expanded theme tags)
 *   popularity  0.10  (track.popularity normalized against the catalog max)
 */
class MusicRecommendationService
{
    private const W_LANGUAGE   = 0.40;
    private const W_MOOD       = 0.30;
    private const W_THEME      = 0.20;
    private const W_POPULARITY = 0.10;

    public const SUPPORTED_LANGUAGES = ['en', 'my', 'td'];

    private const LANGUAGE_NAMES = ['en' => 'English', 'my' => 'Burmese', 'td' => 'Zolai'];

    public function __construct(private MoodExpansionService $moods) {}

    /**
     * Build a playlist for ($language, $mood), excluding recently played track
     * ids. Returns ['playlist' => WorshipTrack[], 'reason' => string,
     * 'themes' => string[]].
     */
    public function recommend(string $language, string $mood, ?int $size = null, array $excludeIds = []): array
    {
        $size   = $this->clampSize($size);
        $themes = $this->moods->expand($mood);

        $candidates = WorshipTrack::query()
            ->where('active', true)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();

        $maxPopularity = (int) $candidates->max('popularity') ?: 1;

        $scored = $candidates
            ->map(fn (WorshipTrack $t) => [
                'track' => $t,
                'score' => $this->score($t, $language, $mood, $themes, $maxPopularity),
            ])
            ->sortByDesc('score')
            ->values();

        // Language is a HARD filter: a worshipper who chose English must only
        // ever hear English worship — never a Burmese/Zolai track mixed in,
        // even once the same-language catalogue runs low. We therefore return
        // ONLY same-language tracks (possibly fewer than $size); the client
        // loops/recycles within the language when it exhausts the catalogue.
        $sameLang = $scored->filter(fn ($r) => $r['track']->language === $language)->values();

        $playlist = $this->pickWithDiversity($sameLang, $size);

        return [
            'playlist' => $playlist,
            'reason'   => $this->reason($mood, $language, $themes, count($playlist)),
            'themes'   => $themes,
        ];
    }

    /** Weighted score in [0, 1] for one track. */
    private function score(WorshipTrack $track, string $language, string $mood, array $themes, int $maxPopularity): float
    {
        $langScore = $track->language === $language ? 1.0 : 0.0;

        $moodScore  = $this->overlap((array) $track->moods, [$this->lower($mood)]);
        $themeScore = $this->overlap((array) $track->themes, $themes);
        $popScore   = $maxPopularity > 0 ? min(1.0, $track->popularity / $maxPopularity) : 0.0;

        return self::W_LANGUAGE * $langScore
            + self::W_MOOD * $moodScore
            + self::W_THEME * $themeScore
            + self::W_POPULARITY * $popScore;
    }

    /** Fraction of $wanted tags present in $have (case-insensitive). */
    private function overlap(array $have, array $wanted): float
    {
        $wanted = array_values(array_filter(array_map([$this, 'lower'], $wanted), fn ($t) => $t !== ''));
        if ($wanted === []) {
            return 0.0;
        }

        $have = array_map([$this, 'lower'], $have);
        $hits = count(array_intersect($wanted, $have));

        return $hits / count($wanted);
    }

    /**
     * Take up to $size tracks from a single (already score-sorted) pool,
     * spreading artists so the same one doesn't play back-to-back when avoidable.
     *
     * Diversity is BEST-EFFORT and never drops a track or reaches outside this
     * pool: if every remaining track shares the last artist (e.g. a catalog
     * where one artist dominates a language), they are still returned in score
     * order. This keeps same-language results from leaking into other languages.
     */
    private function pickWithDiversity($scored, int $size): array
    {
        $remaining = $scored->all();   // ordered rows: ['track'=>, 'score'=>]
        $picked     = [];
        $lastArtist = null;

        while (count($picked) < $size && $remaining !== []) {
            $idx = null;
            // Prefer the highest-scored remaining track by a different artist.
            foreach ($remaining as $i => $row) {
                $artist = $this->lower((string) $row['track']->artist);
                if ($artist === '' || $artist !== $lastArtist) {
                    $idx = $i;
                    break;
                }
            }
            // None differ — accept the top remaining track anyway.
            if ($idx === null) {
                $idx = array_key_first($remaining);
            }

            $row = $remaining[$idx];
            unset($remaining[$idx]);
            $picked[]   = $row['track'];
            $lastArtist = $this->lower((string) $row['track']->artist);
        }

        return $picked;
    }

    /** Build the "I selected these because…" explanation. */
    private function reason(string $mood, string $language, array $themes, int $count): string
    {
        $langName  = self::LANGUAGE_NAMES[$language] ?? $language;
        $moodLabel = trim($mood) !== '' ? trim($mood) : 'worship';
        $topThemes = array_slice(array_values(array_filter($themes, fn ($t) => $t !== $this->lower($mood))), 0, 3);
        $themeText = $topThemes !== [] ? implode(', ', $topThemes) : 'God\'s presence';

        return sprintf(
            'I selected these %d %s worship songs because you mentioned feeling %s — they focus on %s.',
            $count,
            $langName,
            $moodLabel,
            $themeText
        );
    }

    /** Clamp the requested size to the admin-configured [min, max] window. */
    private function clampSize(?int $size): int
    {
        $min = max(1, (int) Setting::get('music.min_playlist', 5));
        $max = max($min, (int) Setting::get('music.max_playlist', 10));
        $size = $size ?? $max;

        return max($min, min($max, $size));
    }

    private function lower(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
