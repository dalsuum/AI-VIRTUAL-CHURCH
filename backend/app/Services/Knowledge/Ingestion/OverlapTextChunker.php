<?php

namespace App\Services\Knowledge\Ingestion;

use App\Services\Knowledge\Contracts\Chunker;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\Document;

/**
 * Prose chunker for sermons, prayers, policies and uploaded documents. Splits on paragraph/
 * sentence boundaries and packs them up to a target size with a sliding OVERLAP, so a fact that
 * straddles a boundary is not lost between chunks. Cleans whitespace first (the "Cleaner" stage).
 */
final class OverlapTextChunker implements Chunker
{
    public function __construct(
        private readonly int $maxChars = 800,
        private readonly int $overlapChars = 120,
    ) {}

    public function chunk(Document $document): array
    {
        $clean = preg_replace('/\s+/u', ' ', trim($document->text)) ?? '';
        if ($clean === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!?؟。])\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [$clean];

        $chunks = [];
        $buffer = '';
        $index = 0;
        foreach ($sentences as $sentence) {
            if (mb_strlen($buffer) + mb_strlen($sentence) > $this->maxChars && $buffer !== '') {
                $chunks[] = $this->make($document, $buffer, $index++);
                $buffer = $this->tail($buffer) . ' ' . $sentence;
            } else {
                $buffer = $buffer === '' ? $sentence : $buffer . ' ' . $sentence;
            }
        }
        if (trim($buffer) !== '') {
            $chunks[] = $this->make($document, $buffer, $index);
        }

        return $chunks;
    }

    private function tail(string $buffer): string
    {
        return $this->overlapChars > 0 ? mb_substr($buffer, -$this->overlapChars) : '';
    }

    private function make(Document $document, string $text, int $index): Chunk
    {
        $meta = $document->metadata;

        return new Chunk(
            id: "{$meta->source}:{$document->id}:{$index}",
            text: trim($text),
            metadata: new ChunkMetadata(
                source: $meta->source,
                language: $meta->language,
                reference: $meta->reference ?? $document->id,
                permissions: $meta->permissions,
                attributes: array_merge($meta->attributes, ['chunk_index' => $index]),
            ),
        );
    }
}
