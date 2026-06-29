<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One observance in the data-driven special-Sundays catalog. The actual date is
 * never stored: occurrenceFor() resolves the row's rule to a concrete Sunday for
 * a given year, so the same row works for every year without maintenance.
 *
 * Three rule types (see config/special_sundays.php):
 *   nth_weekday   — {month, weekday(0=Sun..6=Sat), nth(±1..5, -1 = last)}
 *   easter_offset — {offset} days from Western Easter Sunday
 *   fixed         — {month, day} civil/fixed date
 *
 * Any anchor that is not already a Sunday is snapped to the NEAREST Sunday
 * (within ±3 days) so the observance lands on the church's worship day.
 */
class SpecialSunday extends Model
{
    protected $fillable = [
        'key', 'rule_type', 'rule', 'titles', 'briefs',
        'sermon_tags', 'music_moods', 'content_modes', 'region', 'priority', 'active',
    ];

    protected $casts = [
        'rule'          => 'array',
        'titles'        => 'array',
        'briefs'        => 'array',
        'sermon_tags'   => 'array',
        'music_moods'   => 'array',
        'content_modes' => 'array',
        'priority'      => 'integer',
        'active'        => 'boolean',
    ];

    public function sermons(): HasMany
    {
        return $this->hasMany(SpecialSermon::class);
    }

    public function songs(): HasMany
    {
        return $this->hasMany(SpecialSong::class);
    }

    /**
     * The delivery mode ('auto' | 'manual') for a segment ('sermon' | 'music')
     * in a given language. Defaults to 'auto' when unset, so untouched rows keep
     * the AI/bias behavior.
     */
    public function modeFor(string $segment, string $language): string
    {
        $mode = $this->content_modes[$segment][$language] ?? 'auto';

        return $mode === 'manual' ? 'manual' : 'auto';
    }

    /**
     * Resolve the curated content the worker should use for this observance in a
     * language, honoring the per-language mode and falling back to 'auto' when a
     * 'manual' segment has no active entry. $mood (the worshipper's mood) refines
     * tie-breaking: an entry whose mood matches is preferred, else highest priority.
     *
     * @return array{sermon: array, music: array}
     */
    public function resolveContent(string $language, ?string $mood = null): array
    {
        return [
            'sermon' => $this->resolveSegment('sermon', $language, $mood),
            'music'  => $this->resolveSegment('music', $language, $mood),
        ];
    }

    private function resolveSegment(string $segment, string $language, ?string $mood): array
    {
        if ($this->modeFor($segment, $language) !== 'manual') {
            return ['mode' => 'auto'];
        }

        $entry = $segment === 'sermon'
            ? $this->pickBest($this->sermons, $language, $mood)
            : $this->pickBest($this->songs, $language, $mood);

        if ($entry === null) {
            // Manual requested but nothing active — never strand the service.
            return ['mode' => 'auto', 'fallback' => true];
        }

        if ($segment === 'sermon') {
            return [
                'mode'  => 'manual',
                'id'    => $entry->id,
                'title' => $entry->title,
                'body'  => $entry->body,
            ];
        }

        return [
            'mode'        => 'manual',
            'id'          => $entry->id,
            'title'       => $entry->title,
            'source_type' => $entry->source_type,
            'source_ref'  => $entry->source_ref,
            'lyrics'      => $entry->lyrics,
        ];
    }

    /**
     * Choose the best active entry for a language: prefer one whose mood matches
     * the worshipper's mood, otherwise the highest priority (then newest).
     */
    private function pickBest($collection, string $language, ?string $mood)
    {
        $candidates = $collection
            ->where('active', true)
            ->where('language', $language);

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($mood !== null && $mood !== '') {
            $moodLower = mb_strtolower($mood);
            $byMood = $candidates->filter(
                fn ($e) => $e->mood !== null && str_contains($moodLower, mb_strtolower($e->mood))
            );
            if ($byMood->isNotEmpty()) {
                $candidates = $byMood;
            }
        }

        return $candidates
            ->sortByDesc(fn ($e) => [$e->priority, $e->id])
            ->first();
    }

    /**
     * Resolve this observance to its actual Sunday in the given year, or null if
     * the rule is malformed. Result is always a Sunday (00:00, immutable).
     */
    public function occurrenceFor(int $year): ?CarbonImmutable
    {
        $rule = $this->rule ?? [];

        $anchor = match ($this->rule_type) {
            'nth_weekday'   => $this->resolveNthWeekday($year, $rule),
            'easter_offset' => $this->resolveEasterOffset($year, $rule),
            'fixed'         => $this->resolveFixed($year, $rule),
            default         => null,
        };

        return $anchor === null ? null : $this->snapToNearestSunday($anchor);
    }

    /**
     * The next $count occurrences on/after $from (default today), as immutable
     * Sundays. Walks forward year by year so it works across the year boundary.
     *
     * @return CarbonImmutable[]
     */
    public function nextOccurrences(int $count = 3, ?CarbonImmutable $from = null): array
    {
        $from  = ($from ?? CarbonImmutable::now())->startOfDay();
        $dates = [];
        $year  = $from->year;

        // At most a handful of years to gather $count dates (one per year per rule).
        for ($i = 0; $i < $count + 2 && count($dates) < $count; $i++) {
            $occ = $this->occurrenceFor($year + $i);
            if ($occ !== null && $occ->greaterThanOrEqualTo($from)) {
                $dates[] = $occ;
            }
        }

        return $dates;
    }

    /**
     * Convenience payload for the API / frontend, localized to a service language.
     * Falls back to English text when a translation is missing.
     */
    public function localizedPayload(string $language, CarbonImmutable $sunday): array
    {
        $lang = array_key_exists($language, $this->titles ?? []) ? $language : 'en';

        return [
            'key'         => $this->key,
            'title'       => $this->titles[$lang] ?? $this->titles['en'] ?? $this->key,
            'brief'       => $this->briefs[$lang] ?? $this->briefs['en'] ?? '',
            'sermon_tags' => array_values($this->sermon_tags ?? []),
            'music_moods' => array_values($this->music_moods ?? []),
            'region'      => $this->region,
            'date'        => $sunday->toDateString(),
            'language'    => $lang,
        ];
    }

    // ── rule resolvers ──────────────────────────────────────────────────────

    /** nth weekday of a month; nth < 0 counts from the end (last = -1). */
    private function resolveNthWeekday(int $year, array $rule): ?CarbonImmutable
    {
        $month   = (int) ($rule['month'] ?? 0);
        $weekday = (int) ($rule['weekday'] ?? 0); // 0 = Sunday
        $nth     = (int) ($rule['nth'] ?? 0);

        if ($month < 1 || $month > 12 || $weekday < 0 || $weekday > 6 || $nth === 0) {
            return null;
        }

        if ($nth > 0) {
            $first  = CarbonImmutable::create($year, $month, 1)->startOfDay();
            $offset = ($weekday - $first->dayOfWeek + 7) % 7;
            $date   = $first->addDays($offset + ($nth - 1) * 7);

            return $date->month === $month ? $date : null; // e.g. no 5th Sunday
        }

        // nth < 0 — count back from the last day of the month.
        $last   = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();
        $offset = ($last->dayOfWeek - $weekday + 7) % 7;
        $date   = $last->subDays($offset + (abs($nth) - 1) * 7);

        return $date->month === $month ? $date : null;
    }

    /** A day offset from Western (Gregorian) Easter Sunday. */
    private function resolveEasterOffset(int $year, array $rule): ?CarbonImmutable
    {
        if (! array_key_exists('offset', $rule)) {
            return null;
        }

        return self::westernEaster($year)->addDays((int) $rule['offset']);
    }

    /** A fixed civil date in the given year. */
    private function resolveFixed(int $year, array $rule): ?CarbonImmutable
    {
        $month = (int) ($rule['month'] ?? 0);
        $day   = (int) ($rule['day'] ?? 0);

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /** Snap any date to the nearest Sunday (ties → the following Sunday). */
    private function snapToNearestSunday(CarbonImmutable $date): CarbonImmutable
    {
        $dow = $date->dayOfWeek; // 0 = Sunday
        if ($dow === 0) {
            return $date->startOfDay();
        }

        // Thu(4)..Sat(6) → next Sunday is closer; Mon(1)..Wed(3) → previous Sunday.
        return $dow >= 4
            ? $date->addDays(7 - $dow)->startOfDay()
            : $date->subDays($dow)->startOfDay();
    }

    /**
     * Western Easter Sunday via the Anonymous Gregorian algorithm (Meeus/Jones/
     * Butcher). Kept in-house so we don't depend on a date extension or service.
     */
    public static function westernEaster(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
