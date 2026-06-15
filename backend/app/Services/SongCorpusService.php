<?php

namespace App\Services;

use App\Models\Song;

/**
 * Keeps the worker's Myanmar lyrics corpus in sync with the song library.
 *
 * The `songs` table is the single source of truth (edited from the admin Lyrics
 * tab). The worker side consumes a flat JSON file; this service regenerates that
 * file from the DB so the export is always a derived artifact and can never be
 * hand-edited into drift. Called after every create/update/delete.
 */
class SongCorpusService
{
    /** Worker corpus path, relative to the Laravel base path. */
    private const CORPUS_PATH = '../workers/data/myanmar_lyrics_collection.json';

    /**
     * Rewrite the Myanmar corpus JSON from the current DB contents.
     * Returns the number of songs exported.
     */
    public static function export(): int
    {
        $songs = Song::query()
            ->where('language', 'my')
            ->orderBy('title')
            ->get(['title', 'lyrics', 'source', 'url']);

        $rows = $songs->map(fn (Song $s) => [
            'title'  => $s->title,
            'lyrics' => $s->lyrics,
            'source' => $s->source ?: 'library',
            'url'    => $s->url ?? '',
        ])->values()->all();

        $path = base_path(self::CORPUS_PATH);
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Atomic write so the worker never reads a half-written file.
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $json . "\n");
        rename($tmp, $path);

        return count($rows);
    }
}
