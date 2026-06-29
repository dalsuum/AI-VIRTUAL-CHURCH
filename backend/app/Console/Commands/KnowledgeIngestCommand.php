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
 *   • a directory   → every *.json, *.pdf, *.md and *.txt inside
 *
 * Examples:
 *   php artisan knowledge:ingest
 *   php artisan knowledge:ingest sermon storage/app/knowledge/sermons --chunker=text --lang=en
 *   php artisan knowledge:ingest sermon path/to/easter-2026.pdf
 *   php artisan knowledge:ingest bible  storage/app/knowledge/bible_en.json --chunker=bible
 */
final class KnowledgeIngestCommand extends Command
{
    protected $signature = 'knowledge:ingest {collection?} {file?}
        {--chunker=text : bible|text}
        {--lang=en : language code stamped on PDF/text documents}
        {--source= : metadata source tag (defaults to the collection name)}';

    protected $description = 'Chunk, embed and index documents (JSON, PDF, Markdown or text) into a knowledge corpus';

    public function handle(IngestionPipeline $pipeline, KeywordIndex $keyword, PdfTextExtractor $pdf): int
    {
        $collection = $this->argument('collection');
        $path = $this->argument('file');

        if ($collection === null && $path === null) {
            return $this->ingestDefaultCorpora($pipeline, $keyword, $pdf);
        }

        if ($collection === null || $path === null) {
            $this->error('Provide both {collection} and {file}, or provide neither to ingest the configured first corpus directories.');

            return self::FAILURE;
        }

        return $this->ingestOne($pipeline, $keyword, $pdf, (string) $collection, (string) $path);
    }

    private function ingestOne(IngestionPipeline $pipeline, KeywordIndex $keyword, PdfTextExtractor $pdf, string $collection, string $path): int
    {
        $source = $this->option('source') ?: $collection;

        // Resolve the input into a list of Documents.
        if (is_dir($path)) {
            $documents = $this->loadDirectory($pdf, $path, $source);
            if ($documents === null) {
                return self::FAILURE;
            }
        } elseif (str_ends_with(strtolower($path), '.pdf')) {
            try {
                $documents = [$this->pdfDocument($pdf, $path, $source)];
            } catch (\Throwable $e) {
                $this->error("Could not parse {$path}: {$e->getMessage()}");

                return self::FAILURE;
            }
        } elseif (str_ends_with(strtolower($path), '.json')) {
            $documents = $this->loadJson($path, $source);
            if ($documents === null) {
                return self::FAILURE;
            }
        } elseif ($this->isTextDocument($path)) {
            $documents = [$this->textDocument($path, $source)];
            if ($documents[0]->text === '') {
                $this->warn("Skipping empty text document: {$path}");

                return self::SUCCESS;
            }
        } else {
            $this->error("Unsupported input: {$path} (expected a .json, .pdf, .md, .txt, or a directory)");

            return self::FAILURE;
        }

        $this->ingestDocuments($pipeline, $keyword, $collection, $documents);

        return self::SUCCESS;
    }

    private function ingestDefaultCorpora(IngestionPipeline $pipeline, KeywordIndex $keyword, PdfTextExtractor $pdf): int
    {
        $corpora = (array) config('knowledge.ingestion.default_corpora', []);
        if ($corpora === []) {
            $this->warn('No default knowledge corpora are configured.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($corpora as $collection => $path) {
            $path = (string) $path;
            if (! is_dir($path)) {
                if (! mkdir($path, 0775, true) && ! is_dir($path)) {
                    $this->error("Could not create missing corpus directory: {$path}");

                    return self::FAILURE;
                }
                $this->warn("Created empty corpus directory for '{$collection}': {$path}");
            }

            $documents = $this->loadDirectory($pdf, $path, (string) $collection);
            if ($documents === null) {
                return self::FAILURE;
            }
            $total += $this->ingestDocuments($pipeline, $keyword, (string) $collection, $documents, quietOnEmpty: true);
        }

        $this->info("Default corpus ingest finished; {$total} chunk(s) indexed.");

        return self::SUCCESS;
    }

    /**
     * @param list<Document> $documents
     * @return int number of chunks indexed
     */
    private function ingestDocuments(IngestionPipeline $pipeline, KeywordIndex $keyword, string $collection, array $documents, bool $quietOnEmpty = false): int
    {
        if ($documents === []) {
            if (! $quietOnEmpty) {
                $this->warn('No documents found to ingest.');
            }

            return 0;
        }

        $chunker = $this->option('chunker') === 'bible' ? new BibleVerseChunker() : new OverlapTextChunker();

        $count = $pipeline->ingest(
            $collection,
            $documents,
            $chunker,
            $keyword instanceof InMemoryKeywordIndex ? $keyword : null,
        );

        $this->info("Indexed {$count} chunks from " . count($documents) . " document(s) into '{$collection}'.");
        $this->line("  Chunks created: {$count}");
        $this->line("  Embeddings created: {$count}");
        $this->line('  Indexed in vector store: ' . config('knowledge.vector.driver'));
        $this->line('  Keyword index built: ' . ($keyword instanceof InMemoryKeywordIndex ? 'in-memory seeded' : 'qdrant text payload index'));

        if ($keyword instanceof InMemoryKeywordIndex) {
            $this->warn('NOTE: the in-memory store does not persist across processes. For chat retrieval, set '
                . 'KNOWLEDGE_VECTOR=qdrant + KNOWLEDGE_EMBEDDING=worker and re-run on the worker box.');
        }

        return $count;
    }

    /** @return list<Document>|null null on a parse error */
    private function loadJson(string $path, string $source): ?array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            $this->error('JSON file must contain an array of documents.');

            return null;
        }

        return array_map(function (array $d) use ($source) {
            $metadata = (array) ($d['metadata'] ?? []);
            $metadata['source'] ??= $source;

            return new Document(
                (string) ($d['id'] ?? uniqid('doc_', true)),
                (string) ($d['text'] ?? ''),
                ChunkMetadata::fromArray($metadata),
            );
        }, $raw);
    }

    /**
     * Parse every supported document in a directory. A single corrupt/unreadable file is
     * ISOLATED and skipped with a warning so one bad file can never abort the run.
     *
     * @return list<Document>|null
     */
    private function loadDirectory(PdfTextExtractor $pdf, string $dir, string $source): ?array
    {
        $documents = [];
        $skipped = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo || ! $item->isFile()) {
                continue;
            }
            $file = $item->getPathname();
            $extension = strtolower($item->getExtension());
            if (! in_array($extension, ['json', 'pdf', 'md', 'txt'], true)) {
                continue;
            }

            try {
                if ($extension === 'pdf') {
                    $documents[] = $this->pdfDocument($pdf, $file, $source);
                } elseif ($extension === 'json') {
                    $jsonDocs = $this->loadJson($file, $source);
                    if ($jsonDocs === null) {
                        return null;
                    }
                    array_push($documents, ...$jsonDocs);
                } else {
                    $document = $this->textDocument($file, $source);
                    if ($document->text !== '') {
                        $documents[] = $document;
                    }
                }
                $this->line('  parsed ' . basename($file));
            } catch (\Throwable $e) {
                $skipped[] = basename($file);
                $this->warn('  skipped ' . basename($file) . ' (unreadable document)');
            }
        }

        if ($skipped !== []) {
            $this->warn(count($skipped) . ' file(s) skipped: ' . implode(', ', $skipped));
        }

        return $documents;
    }

    private function isTextDocument(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['md', 'txt'], true);
    }

    private function textDocument(string $file, string $source): Document
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        return new Document(
            id: $source . ':' . \Illuminate\Support\Str::slug($name),
            text: trim((string) file_get_contents($file)),
            metadata: new ChunkMetadata(
                source: $source,
                language: (string) $this->option('lang'),
                reference: $name,
                permissions: ['public'],
                attributes: ['title' => $name, 'original_filename' => basename($file)],
            ),
        );
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
