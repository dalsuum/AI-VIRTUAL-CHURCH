<?php

namespace App\Services\Knowledge\Data;

/**
 * A source document handed to the ingestion pipeline before chunking. Binary formats
 * (PDF/DOCX) are parsed to text by the Python worker; this DTO is the clean, post-parse
 * representation the chunker consumes.
 */
final class Document
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly ChunkMetadata $metadata,
    ) {}
}
