<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * The single abstraction over the vector engine. NOTHING above this interface knows whether
 * vectors live in FAISS (Phase 1, via the worker) or Qdrant (Phase 2). Swapping engines is a
 * binding change — callers, retrievers, prompts and controllers are untouched.
 *
 * `collection` maps to a corpus (bible, sermon, …) so payload filtering and per-tenant scoping
 * stay engine-native.
 */
interface VectorStore
{
    /**
     * Upsert embedded chunks into a collection (ingestion path).
     * @param list<Chunk> $chunks each MUST carry an embedding
     */
    public function upsert(string $collection, array $chunks): void;

    /**
     * Vector similarity search.
     * @param list<float> $vector query embedding
     * @param array<string,mixed> $filters payload filters, e.g. ['language'=>'en','permissions'=>'public']
     * @return list<RetrievedChunk>
     */
    public function search(string $collection, array $vector, int $k, array $filters = []): array;

    /** Remove chunks by id (re-ingestion / takedown). @param list<string> $ids */
    public function delete(string $collection, array $ids): void;
}
