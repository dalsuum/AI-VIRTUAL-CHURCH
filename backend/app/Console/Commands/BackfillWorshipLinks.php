<?php

namespace App\Console\Commands;

use App\Models\WorshipTrack;
use App\Services\YoutubeSongSearchService;
use Illuminate\Console\Command;

/**
 * Fills worship_tracks.youtube_url with a real, embeddable, content-filtered
 * YouTube link by searching for each track's "title artist (language) worship".
 * Use this instead of hand-seeding video ids (which can be wrong/unavailable).
 *
 *   php artisan worship:backfill-links            # only tracks missing a link
 *   php artisan worship:backfill-links --all      # re-fetch every track
 *   php artisan worship:backfill-links --language=my
 */
class BackfillWorshipLinks extends Command
{
    protected $signature = 'worship:backfill-links {--all : Re-fetch even tracks that already have a link} {--language= : Limit to one language (en|my|td)}';

    protected $description = 'Attach real embeddable YouTube links to worship tracks via content-filtered search';

    private const LANG_HINT = ['en' => 'English', 'my' => 'Myanmar Burmese', 'td' => 'Tedim Zolai'];

    public function handle(YoutubeSongSearchService $yt): int
    {
        if (! $yt->isConfigured()) {
            $this->error('YOUTUBE_API_KEY is not set in the backend .env.');
            return self::FAILURE;
        }

        $query = WorshipTrack::query();
        if ($lang = $this->option('language')) {
            $query->where('language', $lang);
        }
        if (! $this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('youtube_url')->orWhere('youtube_url', ''));
        }

        $tracks = $query->get();
        if ($tracks->isEmpty()) {
            $this->info('No tracks to backfill.');
            return self::SUCCESS;
        }

        $filled = 0;
        $missed = 0;
        foreach ($tracks as $track) {
            $hint = self::LANG_HINT[$track->language] ?? '';

            // Try progressively looser queries: the seed artists are invented, so
            // a title+artist search often finds nothing — fall back to title-only.
            $queries = array_unique(array_filter([
                trim(sprintf('%s %s %s worship song', $track->title, $track->artist, $hint)),
                trim(sprintf('%s %s worship song', $track->title, $hint)),
                trim(sprintf('%s %s', $track->title, $hint)),
            ]));

            $results = [];
            foreach ($queries as $q) {
                $results = $yt->search($q, 3);
                if ($results !== []) {
                    break;
                }
            }

            if ($results === []) {
                $missed++;
                $this->line("  <comment>no result</comment>  {$track->language}  {$track->title}");
                continue;
            }

            $track->youtube_url = $results[0]['url'];
            if (! $track->cover_image && ! empty($results[0]['thumbnail'])) {
                $track->cover_image = $results[0]['thumbnail'];
            }
            $track->save();
            $filled++;
            $this->line("  <info>ok</info>        {$track->language}  {$track->title} → {$results[0]['url']}");

            usleep(200_000); // be gentle on the API quota
        }

        $this->info("Done. Filled {$filled}, no result for {$missed}.");
        return self::SUCCESS;
    }
}
