<?php

namespace App\Console\Commands;

use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\Document;
use App\Services\Knowledge\Ingestion\BibleVerseChunker;
use App\Services\Knowledge\Ingestion\IngestionPipeline;
use App\Services\Knowledge\Ingestion\OverlapTextChunker;
use App\Services\Knowledge\Store\InMemoryKeywordIndex;
use Illuminate\Console\Command;

/**
 * CLI/worker ingestion entrypoint — embedding is slow and batched, so it runs OUT of the web
 * request path (this command, typically on the worker box or a queue). Reads a JSON array of
 * {id,text,metadata} documents and indexes them into a corpus collection.
 *
 *   php artisan knowledge:ingest bible storage/app/knowledge/bible_en.json --chunker=bible
 *
 * Binary formats (PDF/DOCX) are parsed to this JSON shape by the Python worker first; this
 * command deliberately does not parse binaries.
 */
final class KnowledgeIngestCommand extends Command
{
    protected $signature = 'knowledge:ingest {collection} {file} {--chunker=text : bible|text}';

    protected $description = 'Chunk, embed and index documents into a knowledge corpus';

    public function handle(IngestionPipeline $pipeline, KeywordIndex $keyword): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            $this->error('File must contain a JSON array of documents.');

            return self::FAILURE;
        }

        $documents = array_map(fn (array $d) => new Document(
            (string) ($d['id'] ?? uniqid('doc_', true)),
            (string) ($d['text'] ?? ''),
            ChunkMetadata::fromArray((array) ($d['metadata'] ?? [])),
        ), $raw);

        $chunker = $this->option('chunker') === 'bible' ? new BibleVerseChunker() : new OverlapTextChunker();

        $count = $pipeline->ingest(
            $this->argument('collection'),
            $documents,
            $chunker,
            $keyword instanceof InMemoryKeywordIndex ? $keyword : null,
        );

        $this->info("Indexed {$count} chunks into '{$this->argument('collection')}'.");

        return self::SUCCESS;
    }
}
