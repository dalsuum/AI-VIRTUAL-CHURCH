<?php

namespace App\Services\Knowledge\Contracts;

/**
 * Turns text into dense vectors. The actual model runs in the Python worker; this seam lets
 * the PHP side stay model-agnostic and lets tests use a deterministic offline embedder. Both
 * ingestion and query-time vector search depend on the SAME implementation so vectors live in
 * one space.
 */
interface EmbeddingService
{
    /**
     * @param list<string> $texts
     * @return list<list<float>> one vector per input, order preserved
     */
    public function embed(array $texts): array;

    /** Embedding dimensionality (used to validate/initialise the vector store). */
    public function dimensions(): int;

    /**
     * Stable model+version identifier (e.g. 'hash-v1:256', 'bge-m3:1'). Stamped onto every
     * chunk at ingestion so a later model change is detectable — vectors embedded by a
     * different model are "logically stale but technically valid" without this tag.
     */
    public function model(): string;
}
