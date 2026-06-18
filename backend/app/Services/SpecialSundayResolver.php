<?php

namespace App\Services;

use App\Models\SpecialSunday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Decides which special Sunday (if any) is "active" for a given moment.
 *
 * Window math
 * -----------
 * An observance resolves to a Sunday S (see SpecialSunday::occurrenceFor). Its
 * active window is:
 *
 *     [ S − 2 days @ 00:00:00  ..  S @ 23:59:59 ]
 *     = Friday 00:00:00  →  Sunday 23:59:59
 *
 * So a worshipper arriving Friday, Saturday, or Sunday of that week sees the
 * highlight and gets the biased sermon/worship; Monday–Thursday they do not.
 *
 * To find candidates for a date D we only need to test the Sundays of the
 * current week and the *next* week (D itself can be at most 2 days before its
 * Sunday, and at most in the same week as a Sunday that already passed). When
 * several windows overlap, the highest `priority` wins.
 */
class SpecialSundayResolver
{
    private const CACHE_TTL = 600; // seconds — cheap, but avoids recomputing per request

    /**
     * The active observance for $date, or null. Returns the raw model plus the
     * resolved Sunday so callers can localize without recomputing the date.
     *
     * @return array{special: SpecialSunday, sunday: CarbonImmutable}|null
     */
    public function activeFor(?CarbonInterface $date = null): ?array
    {
        $moment = CarbonImmutable::instance($date ?? CarbonImmutable::now());

        // Candidate Sundays whose Fri–Sun window could contain $moment: this
        // week's Sunday and next week's Sunday.
        $thisSunday = $moment->startOfWeek(CarbonInterface::SUNDAY);
        $candidateSundays = [$thisSunday, $thisSunday->addWeek()];

        $best = null;

        foreach (SpecialSunday::where('active', true)->get() as $special) {
            foreach ($candidateSundays as $sunday) {
                $occurrence = $special->occurrenceFor($sunday->year);
                if ($occurrence === null || ! $occurrence->isSameDay($sunday)) {
                    continue;
                }

                if (! $this->withinWindow($moment, $occurrence)) {
                    continue;
                }

                if ($best === null || $special->priority > $best['special']->priority) {
                    $best = ['special' => $special, 'sunday' => $occurrence];
                }
            }
        }

        return $best;
    }

    /**
     * Localized payload for the active observance, or null. Cached briefly per
     * (date, language) so the public endpoint and the dispatch path stay cheap.
     */
    public function currentPayload(string $language = 'en', ?CarbonInterface $date = null): ?array
    {
        $moment = CarbonImmutable::instance($date ?? CarbonImmutable::now());
        $key = 'special_sunday:current:' . $moment->toDateString() . ':' . $language;

        return Cache::remember($key, self::CACHE_TTL, function () use ($language, $moment) {
            $active = $this->activeFor($moment);

            return $active === null
                ? null
                : $active['special']->localizedPayload($language, $active['sunday']);
        });
    }

    /** True when $moment falls in [Sunday − 2 days @ 00:00 .. Sunday @ 23:59:59]. */
    private function withinWindow(CarbonImmutable $moment, CarbonImmutable $sunday): bool
    {
        $start = $sunday->subDays(2)->startOfDay(); // Friday 00:00:00
        $end   = $sunday->endOfDay();               // Sunday 23:59:59

        return $moment->betweenIncluded($start, $end);
    }
}
