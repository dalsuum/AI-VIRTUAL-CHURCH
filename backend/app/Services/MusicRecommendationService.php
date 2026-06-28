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
 *   mood        0.30  (overlap of track.moods with the expanded concept tags)
 *   theme       0.20  (overlap of track.themes with the expanded concept tags)
 *   popularity  0.10  (track.popularity normalized against the catalog max)
 *
 * Moods, language names, search hints and broad fallback queries all live in
 * config/worship_moods.php — the single source of truth shared with the UI and
 * MoodExpansionService.
 */
class MusicRecommendationService
{
    private const W_LANGUAGE   = 0.40;
    private const W_MOOD       = 0.30;
    private const W_THEME      = 0.20;
    private const W_POPULARITY = 0.10;

    public function __construct(
        private MoodExpansionService $moods,
        private YoutubeSongSearchService $youtube,
    ) {}

    /** Supported language codes, from config (used by request validation). */
    public static function supportedLanguages(): array
    {
        return array_keys((array) config('worship_moods.languages', []));
    }

    /**
     * Build a playlist for ($language, $mood), excluding recently played track
     * ids. Returns ['playlist' => WorshipTrack[], 'reason' => string,
     * 'themes' => string[]].
     */
    public function recommend(string $language, string $mood, ?int $size = null, array $excludeIds = []): array
    {
        $size   = $this->clampSize($size);
        $themes = $this->moods->expand($mood);

        // Keep the catalogue fresh: when the same-language pool the worshipper
        // hasn't just heard can't fill a playlist, discover new embeddable songs
        // from YouTube and persist them. This is what stops the radio looping the
        // same handful of seeded tracks. Best-effort — never blocks a response.
        $this->maybeDiscover($language, $mood, $themes, $size, $excludeIds);

        $candidates = WorshipTrack::query()
            ->where('active', true)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get()
            // Re-apply the content filter at serve time: discovery only screens
            // NEW search hits, so a channel/URL blocked after a track was already
            // persisted would otherwise keep playing. This drops it on the next
            // request — no purge needed. The same self-heal drops auto-discovered
            // tracks whose title script doesn't match their language (e.g. a Hindi
            // title saved as `en` before this gate existed) — curated catalogue
            // rows are left untouched.
            ->filter(fn (WorshipTrack $t) => $this->passesContentFilter($t)
                && ($t->copyright_status !== 'metadata_only'
                    || $this->titleLanguageScriptOk((string) $t->title, (string) $t->language)))
            ->values();

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

        // $themes is the expanded concept set (mood id "relax" + peace/anxiety/…),
        // so a curated track tagged moods:['anxiety'] still matches the "relax"
        // chip. We match the track's mood tags AND its theme tags against the
        // same concept set — the abstract mood id alone would match nothing.
        $moodScore  = $this->overlap((array) $track->moods, $themes);
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
        $langName  = config("worship_moods.languages.{$language}.name", $language);
        // Show the worshipper's English mood label ("Relax") rather than the raw
        // id; free text falls back to what they typed.
        $moodLabel = $this->moods->label($mood, 'en');
        $moodLabel = trim($moodLabel) !== '' ? $moodLabel : 'worship';
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

    /**
     * Live YouTube search on a mood request: discovers fresh embeddable songs and
     * persists them into the catalogue. Fires on the first search of a mood (per
     * the cache TTL) and whenever the playable, un-played same-language pool can't
     * fill a playlist. Requires discovery enabled and a configured key. Persisted
     * tracks are deduped by youtube_url so repeat searches don't bloat the table.
     */
    private function maybeDiscover(string $language, string $mood, array $themes, int $size, array $excludeIds): void
    {
        if (! $this->youtube->isConfigured()) {
            return;
        }
        if (! filter_var(Setting::get('music.youtube_discovery', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Live search: hit YouTube whenever the worshipper searches a mood, so the
        // radio surfaces fresh uploads — not just the seeded catalogue. To protect
        // the daily API quota we cache a per-(language, mood) marker: the first
        // search for a mood goes live; repeats within the TTL reuse what we just
        // persisted. A thin/exhausted same-language pool always forces a live hit.
        $playable = WorshipTrack::query()
            ->where('active', true)
            ->where('language', $language)
            ->whereNotNull('youtube_url')
            ->where('youtube_url', '!=', '')
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->count();

        $ttl       = max(60, (int) Setting::get('music.youtube_discovery_ttl', 21600)); // 6h default
        // Fold the content-filter epoch into the key so any block/allow edit
        // invalidates every cached discovery marker and forces a fresh, filtered search.
        $epoch     = (string) Setting::get('content_filter_epoch', '0');
        $cacheKey  = 'music:yt-discover:' . $epoch . ':' . $language . ':' . md5($this->lower($mood));
        $searchedRecently = \Illuminate\Support\Facades\Cache::has($cacheKey);

        // Skip the network call only when we already searched this mood recently
        // AND there is still enough un-played material to fill a playlist.
        if ($searchedRecently && $playable >= $size) {
            return;
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, true, $ttl);

        // Try the mood-specific query first, then progressively broader proven
        // terms, accumulating (deduped by url) until we have enough fresh songs
        // to top the same-language pool up to a full playlist — not stopping at
        // the first query with *any* hit. This is the "expand keywords and search
        // again" fallback: without it, a sparse catalogue (e.g. Zolai) plus an
        // over-specific native query yields zero results and the radio reports
        // "no songs" despite music being findable; stopping at the first hit
        // could leave a 2-song discovery batch when a broader query has more.
        $results = [];
        try {
            foreach ($this->discoveryQueries($language, $mood, $themes) as $query) {
                if ($query === '') {
                    continue;
                }
                foreach ($this->youtube->search($query, 8) as $r) {
                    if (! empty($r['url'])) {
                        $results[$r['url']] = $r;   // url key de-dupes across queries
                    }
                }
                if (count($results) >= $size) {
                    break;
                }
            }
            $results = array_values($results);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Worship YouTube discovery failed', [
                'error' => $e->getMessage(), 'language' => $language, 'mood' => $mood,
            ]);
            return;
        }

        foreach ($results as $r) {
            if (empty($r['url'])) {
                continue;
            }

            // Don't persist a result whose title is in a script that doesn't
            // belong to the searched language: an English search can return a
            // Hindi (Devanagari) or Chinese (CJK) upload, and stamping it `en`
            // contaminates the catalogue. (Title is the reliable signal; channel
            // branding is often Latin regardless of the song's language.)
            if (! $this->titleLanguageScriptOk((string) $r['title'], $language)) {
                continue;
            }

            WorshipTrack::updateOrCreate(
                ['youtube_url' => $r['url']],
                [
                    'title'            => $r['title'] !== '' ? mb_substr($r['title'], 0, 255) : 'Worship Song',
                    'artist'          => $r['channel'] !== '' ? mb_substr($r['channel'], 0, 255) : null,
                    'language'        => $language,
                    'genre'           => 'worship',
                    'themes'          => array_slice($themes, 0, 8),
                    'moods'           => [$this->lower($mood)],
                    'cover_image'     => $r['thumbnail'] ?? null,
                    'copyright_status' => 'metadata_only',
                    'popularity'      => 20,   // below curated seeds, above nothing.
                    'active'          => true,
                ],
            );
        }
    }

    /**
     * Whether a catalogue track survives the admin content filter. Matches the
     * block/allow firewall used by YouTube discovery (scope 'sermon') against the
     * track's title, artist (= channel), and URL — so blocking a channel by name,
     * id, or URL also removes tracks saved before the block. Allow wins over block.
     */
    private function passesContentFilter(WorshipTrack $track): bool
    {
        $haystack = mb_strtolower(trim(
            $track->title . ' ' . $track->artist . ' ' . $track->youtube_url
        ));
        if ($haystack === '') {
            return true;
        }
        foreach (Setting::allowKeywordsForScope('sermon') as $a) {
            if ($a !== '' && str_contains($haystack, mb_strtolower($a))) {
                return true;
            }
        }
        foreach (Setting::filterKeywordsForScope('sermon', 'block') as $b) {
            if ($b !== '' && str_contains($haystack, mb_strtolower($b))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a title's script is compatible with the target language, used to
     * keep cross-language YouTube results out of a catalogue. Latin is allowed
     * for every language (en/td are Latin-script; Burmese titles are often
     * romanised); Myanmar script is additionally allowed for `my`. A title that
     * carries any OTHER major script (Devanagari, CJK/kana/Hangul, Arabic,
     * Hebrew, Thai, Cyrillic, other Indic) belongs to a different language and
     * is rejected. Emoji/punctuation/digits live outside these ranges and are
     * ignored, so they never trigger a false rejection.
     */
    private function titleLanguageScriptOk(string $title, string $language): bool
    {
        // Foreign-script Unicode ranges that signal a non-target language.
        $foreign = [
            'myanmar'    => '\x{1000}-\x{109F}',
            'devanagari' => '\x{0900}-\x{097F}',
            'bengali'    => '\x{0980}-\x{09FF}',
            'tamil'      => '\x{0B80}-\x{0BFF}',
            'telugu'     => '\x{0C00}-\x{0C7F}',
            'thai'       => '\x{0E00}-\x{0E7F}',
            'arabic'     => '\x{0600}-\x{06FF}',
            'hebrew'     => '\x{0590}-\x{05FF}',
            'cyrillic'   => '\x{0400}-\x{04FF}',
            'cjk'        => '\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}', // kana + CJK + Hangul
        ];
        // Scripts a language legitimately uses besides Latin.
        $allowed = ['my' => ['myanmar']];

        foreach ($foreign as $name => $range) {
            if (in_array($name, $allowed[$language] ?? [], true)) {
                continue;
            }
            if (preg_match('/[' . $range . ']/u', $title)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ordered specific→broad YouTube queries for discovery. The first is the
     * mood-specific native query; the rest are the language's proven broad
     * fallbacks. maybeDiscover() walks the list, accumulating results until it
     * has a full playlist's worth, so a sparse catalogue still surfaces enough
     * worship music instead of nothing (or a 2-song batch from a thin query).
     */
    private function discoveryQueries(string $language, string $mood, array $themes): array
    {
        $topTheme = '';
        foreach ($themes as $t) {
            if ($t !== $this->lower($mood)) {
                $topTheme = $t;
                break;
            }
        }
        $hint = config("worship_moods.languages.{$language}.hint", 'worship song');

        // Search in the worshipper's language: a chip mood ("feel_good") becomes
        // its native term ("ပျော်ရွှင်" / "Lungdam") so discovery surfaces real
        // Burmese/Zolai worship instead of English-keyword results. Free text the
        // worshipper typed is already in their language and passes through as-is.
        $nativeMood = $this->moods->label($mood, $language);

        $specific = trim(sprintf('%s %s %s', trim($nativeMood), $topTheme, $hint));

        $fallbacks = (array) config("worship_moods.languages.{$language}.fallback", ['worship song']);

        return array_values(array_unique(array_merge([$specific], $fallbacks)));
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
