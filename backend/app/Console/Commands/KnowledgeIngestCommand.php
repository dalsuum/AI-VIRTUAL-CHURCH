<?php

namespace App\Console\Commands;

use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\Document;
use App\Services\Knowledge\Ingestion\BibleVerseChunker;
use App\Services\Knowledge\Ingestion\IngestionPipeline;
use App\Services\Knowledge\Ingestion\OverlapTextChunker;
use App\Services\Knowledge\Ingestion\PdfTextExtractor;
use App\Services\Knowledge\Store\InMemoryKeywordIndex;
use Illuminate\Console\Command;

/**
 * CLI/worker ingestion entrypoint — embedding is slow and batched, so it runs OUT of the web
 * request path. Accepts three input shapes, auto-detected from {file}:
 *
 *   • a .json file  → array of {id,text,metadata} documents
 *   • a .pdf file   → parsed to text via pdftotext (poppler-utils)
 *   • a directory   → every *.pdf inside, each one document
 *
 * Examples:
 *   php artisan knowledge:ingest sermon storage/app/knowledge/sermons --chunker=text --lang=en
 *   php artisan knowledge:ingest sermon path/to/easter-2026.pdf
 *   php artisan knowledge:ingest bible  storage/app/knowledge/bible_en.json --chunker=bible
 */
final class KnowledgeIngestCommand extends Command
{
    protected $signature = 'knowledge:ingest {collection} {file}
        {--chunker=text : bible|text}
        {--lang=en : language code stamped on PDF/text documents}
        {--source= : metadata source tag (defaults to the collection name)}';

    protected $description = 'Chunk, embed and index documents (JSON or PDF) into a knowledge corpus';

    public function handle(IngestionPipeline $pipeline, KeywordIndex $keyword, PdfTextExtractor $pdf): int
    {
        $path = $this->argument('file');
        $collection = $this->argument('collection');
        $source = $this->option('source') ?: $collection;

        // Resolve the input into a list of Documents.
        if (is_dir($path)) {
            $documents = $this->loadPdfDirectory($pdf, $path, $source);
        } elseif (str_ends_with(strtolower($path), '.pdf')) {
            try {
                $documents = [$this->pdfDocument($pdf, $path, $source)];
            } catch (\Throwable $e) {
                $this->error("Could not parse {$path}: {$e->getMessage()}");

                return self::FAILURE;
            }
        } elseif (str_ends_with(strtolower($path), '.json')) {
            $documents = $this->loadJson($path);
            if ($documents === null) {
                return self::FAILURE;
            }
        } else {
            $this->error("Unsupported input: {$path} (expected a .json file, a .pdf file, or a directory of PDFs)");

            return self::FAILURE;
        }

        if ($documents === []) {
            $this->warn('No documents found to ingest.');

            return self::SUCCESS;
        }

        $chunker = $this->option('chunker') === 'bible' ? new BibleVerseChunker() : new OverlapTextChunker();

        $count = $pipeline->ingest(
            $collection,
            $documents,
            $chunker,
            $keyword instanceof InMemoryKeywordIndex ? $keyword : null,
        );

        $this->info("Indexed {$count} chunks from " . count($documents) . " document(s) into '{$collection}'.");

        if ($keyword instanceof InMemoryKeywordIndex) {
            $this->warn('NOTE: the in-memory store does not persist across processes. For chat retrieval, set '
                . 'KNOWLEDGE_VECTOR=qdrant + KNOWLEDGE_EMBEDDING=worker and re-run on the worker box.');
        }

        return self::SUCCESS;
    }

    /** @return list<Document>|null null on a parse error */
    private function loadJson(string $path): ?array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            $this->error('JSON file must contain an array of documents.');

            return null;
        }

        return array_map(fn (array $d) => new Document(
            (string) ($d['id'] ?? uniqid('doc_', true)),
            (string) ($d['text'] ?? ''),
            ChunkMetadata::fromArray((array) ($d['metadata'] ?? [])),
        ), $raw);
    }

    /**
     * Parse every PDF in a directory. A single corrupt/unreadable PDF is ISOLATED — it is
     * skipped with a warning and the batch continues — so one bad file can never abort the run
     * (the same "bad data is isolated, never propagated" invariant the retrieval layer enforces).
     *
     * @return list<Document>
     */
    private function loadPdfDirectory(PdfTextExtractor $pdf, string $dir, string $source): array
    {
        $documents = [];
        $skipped = [];
        foreach (glob(rtrim($dir, '/') . '/*.pdf') ?: [] as $file) {
            try {
                $documents[] = $this->pdfDocument($pdf, $file, $source);
                $this->line('  parsed ' . basename($file));
            } catch (\Throwable $e) {
                $skipped[] = basename($file);
                $this->warn('  skipped ' . basename($file) . ' (unreadable PDF)');
            }
        }

        if ($skipped !== []) {
            $this->warn(count($skipped) . ' file(s) skipped: ' . implode(', ', $skipped));
        }

        return $documents;
    }

    private function pdfDocument(PdfTextExtractor $pdf, string $file, string $source): Document
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        return new Document(
            id: $source . ':' . \Illuminate\Support\Str::slug($name),
            text: $pdf->extract($file),
            metadata: new ChunkMetadata(
                source: $source,
                language: (string) $this->option('lang'),
                reference: $name,
                permissions: ['public'],
                attributes: ['title' => $name, 'original_filename' => basename($file)],
            ),
        );
    }
}
