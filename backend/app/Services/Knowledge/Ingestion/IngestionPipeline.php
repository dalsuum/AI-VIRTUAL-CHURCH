<?php

namespace App\Services\Knowledge\Ingestion;

use App\Services\Knowledge\Contracts\Chunker;
use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\Document;

/**
 * The write-side counterpart to retrieval, deliberately SEPARATE from it: document → chunk →
 * embed → index (both vector and keyword). Designed to run in WORKERS / console, never in a web
 * request (embedding is slow and batched). Idempotent: stable chunk ids mean re-ingestion
 * upserts instead of duplicating.
 */
final class IngestionPipeline
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly VectorStore $vectors,
        private readonly int $batchSize = 64,
    ) {}

    /**
     * Ingest documents into a collection using the given chunker. The KeywordIndex is optional
     * because the production keyword path may be a DB FULLTEXT populated separately.
     *
     * @param list<Document> $documents
     * @return int number of chunks indexed
     */
    public function ingest(string $collection, array $documents, Chunker $chunker, ?KeywordIndex $keyword = null, string $corpusVersion = 'v1'): int
    {
        $allChunks = [];
        foreach ($documents as $document) {
            foreach ($chunker->chunk($document) as $chunk) {
                $allChunks[] = $this->stamp($chunk, $corpusVersion);
            }
        }
        if ($allChunks === []) {
            return 0;
        }

        foreach (array_chunk($allChunks, $this->batchSize) as $batch) {
            $vectors = $this->embeddings->embed(array_map(static fn ($c) => $c->text, $batch));
            $embedded = [];
            foreach ($batch as $i => $chunk) {
                $embedded[] = isset($vectors[$i]) ? $chunk->withEmbedding($vectors[$i]) : $chunk;
            }
            $this->vectors->upsert($collection, $embedded);

            if ($keyword instanceof \App\Services\Knowledge\Store\InMemoryKeywordIndex) {
                $keyword->seed($collection, $batch);
            }
        }

        return count($allChunks);
    }

    /**
     * Stamp version provenance onto a chunk so the index can be audited and selectively
     * re-embedded later: which embedding model produced the vector, and which corpus revision
     * the text came from. Without these, Qdrant silently accumulates mixed-model vectors.
     */
    private function stamp(Chunk $chunk, string $corpusVersion): Chunk
    {
        $meta = $chunk->metadata;

        return new Chunk(
            $chunk->id,
            $chunk->text,
            new ChunkMetadata(
                source: $meta->source,
                language: $meta->language,
                reference: $meta->reference,
                confidence: $meta->confidence,
                permissions: $meta->permissions,
                attributes: array_merge($meta->attributes, [
                    'embedding_model' => $this->embeddings->model(),
                    'corpus_version'  => $corpusVersion,
                    'ingested_at'     => gmdate('c'),
                ]),
            ),
            $chunk->embedding,
        );
    }
}
