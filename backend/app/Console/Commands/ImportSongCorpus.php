<?php

namespace App\Console\Commands;

use App\Models\Song;
use Illuminate\Console\Command;

/**
 * One-time migration of the legacy Myanmar lyrics JSON corpus into the `songs`
 * table (the new single source of truth). Idempotent: songs whose title already
 * exists are skipped, so it is safe to re-run.
 */
class ImportSongCorpus extends Command
{
    protected $signature = 'songs:import-corpus {--file=} {--language=my}';
    protected $description = 'Import the legacy Myanmar lyrics JSON corpus into the songs table';

    public function handle(): int
    {
        $file = $this->option('file')
            ?: base_path('../workers/data/myanmar_lyrics_collection.json');

        if (! is_file($file)) {
            $this->error("Corpus file not found: {$file}");

            return self::FAILURE;
        }

        $entries = json_decode((string) file_get_contents($file), true);
        if (! is_array($entries)) {
            $this->error('Corpus file is not a valid JSON array.');

            return self::FAILURE;
        }

        $language = $this->option('language');
        $existing = Song::pluck('id', 'title');   // title => id, for dedupe
        $imported = 0;
        $skipped  = 0;

        foreach ($entries as $entry) {
            $title  = trim((string) ($entry['title'] ?? ''));
            $lyrics = (string) ($entry['lyrics'] ?? '');

            if ($title === '' || trim($lyrics) === '') {
                $skipped++;
                continue;
            }
            if ($existing->has($title)) {
                $skipped++;
                continue;
            }

            Song::create([
                'language'   => $language,
                'title'      => $title,
                'artist'     => null,
                'category'   => null,
                'lyrics'     => $lyrics,
                'has_chords' => (bool) preg_match('/\[[A-G][^\s\]]*\]/', $lyrics),
                'source'     => (string) ($entry['source'] ?: 'corpus') ?: 'corpus',
                'url'        => ($entry['url'] ?? null) ?: null,
            ]);

            $existing->put($title, true);   // guard against in-file duplicates
            $imported++;
        }

        $this->info("Imported {$imported} song(s); skipped {$skipped} (empty or duplicate).");

        return self::SUCCESS;
    }
}
