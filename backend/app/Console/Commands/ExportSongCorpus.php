<?php

namespace App\Console\Commands;

use App\Services\SongCorpusService;
use Illuminate\Console\Command;

/**
 * Regenerates the worker Myanmar lyrics corpus JSON from the songs table. The DB
 * is authoritative; this just refreshes the derived export (also done
 * automatically after every admin write — see SongController).
 */
class ExportSongCorpus extends Command
{
    protected $signature = 'songs:export-corpus';
    protected $description = 'Export the songs table to the worker Myanmar lyrics corpus JSON';

    public function handle(): int
    {
        $count = SongCorpusService::export();
        $this->info("Exported {$count} Myanmar song(s) to the worker corpus.");

        return self::SUCCESS;
    }
}
