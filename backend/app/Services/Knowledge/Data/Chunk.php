<?php

namespace App\Services\Knowledge\Data;

/**
 * The atomic unit of knowledge: a retrievable span of text plus its provenance. `id` is
 * stable (derived from source + reference) so re-ingestion upserts rather than duplicates.
 */
final class Chunk
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly ChunkMetadata $metadata,
        /** @var list<float>|null cached embedding (set during ingestion; null at query time) */
        public readonly ?array $embedding = null,
    ) {}

    public function withEmbedding(array $embedding): self
    {
        return new self($this->id, $this->text, $this->metadata, $embedding);
    }
}
