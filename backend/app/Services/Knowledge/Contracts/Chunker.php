<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\Document;

/**
 * Splits a document into retrieval-sized chunks. Strategy varies by source (verse/chapter
 * boundaries for the Bible, semantic + overlap for prose), so it is an interface chosen per
 * corpus during ingestion — never a single fixed-size splitter.
 */
interface Chunker
{
    /** @return list<Chunk> chunks WITHOUT embeddings (the pipeline embeds afterwards) */
    public function chunk(Document $document): array;
}
