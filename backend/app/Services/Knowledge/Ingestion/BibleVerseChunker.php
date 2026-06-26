<?php

namespace App\Services\Knowledge\Ingestion;

use App\Services\Knowledge\Contracts\Chunker;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\Document;

/**
 * Bible-aware chunking. Scripture has natural boundaries (verse, chapter, pericope) that beat
 * arbitrary fixed-size splits for retrieval and citation. This chunker expects the document's
 * attributes to carry book/chapter and the text to be verse-delimited ("1 In the beginning…"),
 * emitting one chunk per verse with a precise reference ("Genesis 1:1") in metadata — the
 * provenance CitationGuard relies on.
 */
final class BibleVerseChunker implements Chunker
{
    public function chunk(Document $document): array
    {
        $meta = $document->metadata;
        $book = (string) ($meta->attributes['book'] ?? 'Unknown');
        $chapter = (string) ($meta->attributes['chapter'] ?? '1');

        $chunks = [];
        // Split on a verse-number boundary while keeping the number.
        $parts = preg_split('/(?=\b\d{1,3}\s)/u', trim($document->text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            if (! preg_match('/^(\d{1,3})\s+(.*)$/us', trim($part), $m)) {
                continue;
            }
            $verse = $m[1];
            $text = trim($m[2]);
            $reference = "{$book} {$chapter}:{$verse}";

            $chunks[] = new Chunk(
                id: 'bible:' . mb_strtolower("{$book}.{$chapter}.{$verse}.{$meta->language}"),
                text: $text,
                metadata: new ChunkMetadata(
                    source: 'bible',
                    language: $meta->language,
                    reference: $reference,
                    permissions: $meta->permissions,
                    attributes: array_merge($meta->attributes, [
                        'book' => $book, 'chapter' => (int) $chapter, 'verse' => (int) $verse,
                        'translation' => $meta->attributes['translation'] ?? null,
                    ]),
                ),
            );
        }

        return $chunks;
    }
}
