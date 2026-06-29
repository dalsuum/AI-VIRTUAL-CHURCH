<?php

namespace App\Jobs;

use App\Models\KnowledgeIngestJob as IngestRecord;
use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Ingestion\BibleVerseChunker;
use App\Services\Knowledge\Ingestion\IngestionPipeline;
use App\Services\Knowledge\Ingestion\OverlapTextChunker;
use App\Services\Knowledge\Ingestion\PdfTextExtractor;
use App\Services\Knowledge\Data\Document;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Store\InMemoryKeywordIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Processes a single knowledge corpus ingestion: chunks, embeds, and indexes
 * the uploaded document. Updates the knowledge_ingest_jobs record throughout so
 * the admin dashboard can show real-time status.
 *
 * Failure isolation: any exception marks the record as 'failed' with the error
 * message; it never silently disappears. The job is NOT retried automatically
 * because re-ingesting a duplicate would inflate vector counts — an admin retry
 * via the dashboard is intentional and explicit.
 */
class KnowledgeIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // No auto-retry; admin retries explicitly from the dashboard.
    public int $timeout = 300;

    public function __construct(private readonly int $ingestJobId) {}

    public function handle(
        IngestionPipeline $pipeline,
        PdfTextExtractor  $pdf,
        KeywordIndex      $keyword,
    ): void {
        $record = IngestRecord::findOrFail($this->ingestJobId);

        if ($record->status === 'cancelled') {
            return;
        }

        $record->update(['status' => 'processing']);
        $startMs = (int) round(microtime(true) * 1000);

        try {
            $documents = $this->loadDocuments($record, $pdf);
            $record->update(['document_count' => count($documents)]);

            $meta     = (array) ($record->metadata ?? []);
            $chunker  = ($meta['chunker'] ?? 'text') === 'bible'
                ? new BibleVerseChunker()
                : new OverlapTextChunker();

            $count      = $pipeline->ingest($record->collection, $documents, $chunker);
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            // Compute average chunk size across all documents (approx from source text length).
            $totalChars = array_sum(array_map(fn ($d) => mb_strlen($d->text), $documents));
            $avgChunkSize = $count > 0 ? (int) round($totalChars / $count) : 0;

            $completionMeta = array_merge((array) ($record->metadata ?? []), [
                'embedding_driver' => config('knowledge.embedding.driver', 'hash'),
                'embedding_dims'   => (int) config('knowledge.embedding.dimensions', 384),
                'avg_chunk_size'   => $avgChunkSize,
            ]);

            $record->update([
                'status'      => 'completed',
                'chunk_count' => $count,
                'duration_ms' => $durationMs,
                'metadata'    => $completionMeta,
            ]);

            Log::info('knowledge.ingest_job.completed', [
                'job_id'      => $record->id,
                'collection'  => $record->collection,
                'chunks'      => $count,
                'documents'   => count($documents),
                'duration_ms' => $durationMs,
                'avg_chunk_size' => $avgChunkSize,
            ]);
        } catch (\Throwable $e) {
            $record->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('knowledge.ingest_job.failed', [
                'job_id' => $record->id,
                'error'  => $e->getMessage(),
            ]);
            throw $e; // propagate so the queue marks the job as failed
        } finally {
            // Clean up the temp file regardless of outcome.
            if ($record->storage_path && Storage::exists($record->storage_path)) {
                Storage::delete($record->storage_path);
            }
        }
    }

    /** @return list<Document> */
    private function loadDocuments(IngestRecord $record, PdfTextExtractor $pdf): array
    {
        $path     = Storage::path($record->storage_path);
        $ext      = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $meta     = (array) ($record->metadata ?? []);
        $source   = (string) ($meta['source'] ?? $record->collection);
        $language = (string) ($meta['language'] ?? 'en');

        return match ($ext) {
            'pdf'  => [$this->pdfDoc($pdf, $path, $source, $language)],
            'json' => $this->jsonDocs($path, $source),
            default => [$this->textDoc($path, $record->original_filename, $source, $language)],
        };
    }

    private function pdfDoc(PdfTextExtractor $pdf, string $path, string $source, string $language): Document
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        return new Document(
            id: $source . ':' . \Illuminate\Support\Str::slug($name),
            text: $pdf->extract($path),
            metadata: new ChunkMetadata(source: $source, language: $language, reference: $name, permissions: ['public']),
        );
    }

    private function textDoc(string $path, string $filename, string $source, string $language): Document
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        return new Document(
            id: $source . ':' . \Illuminate\Support\Str::slug($name),
            text: trim((string) file_get_contents($path)),
            metadata: new ChunkMetadata(
                source: $source,
                language: $language,
                reference: $name,
                permissions: ['public'],
                attributes: ['title' => $name, 'original_filename' => $filename],
            ),
        );
    }

    /** @return list<Document> */
    private function jsonDocs(string $path, string $source): array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            throw new \RuntimeException("Invalid JSON document: {$path}");
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
}
