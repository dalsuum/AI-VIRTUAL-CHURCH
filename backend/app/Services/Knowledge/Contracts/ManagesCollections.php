<?php

namespace App\Services\Knowledge\Contracts;

/**
 * Optional capability for vector stores that own their collections (Qdrant). The ingestion
 * pipeline calls ensureCollection() before upserting so a fresh install provisions itself —
 * correct vector dimensionality + a full-text index on the chunk text (which powers the
 * persistent keyword branch). In-memory stores don't implement this; the pipeline just skips it.
 */
interface ManagesCollections
{
    /** Idempotently create the collection (vector size = $dimensions) + a text payload index. */
    public function ensureCollection(string $collection, int $dimensions): void;

    /**
     * Whether the collection currently exists, so retrieval can skip not-yet-ingested corpora
     * instead of issuing failing requests. Implementations SHOULD cache and MUST fail open
     * (return true when existence can't be determined) so a backend outage still flows through
     * the normal degraded/failed path rather than masquerading as a clean no-match.
     */
    public function hasCollection(string $collection): bool;
}
