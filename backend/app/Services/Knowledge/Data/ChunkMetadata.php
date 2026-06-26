<?php

namespace App\Services\Knowledge\Data;

/**
 * Structured provenance for a chunk. Carried end-to-end so downstream consumers — Citation,
 * Hallucination and Theology guards, the Prompt Builder, analytics and per-tenant filtering —
 * always know WHERE a piece of context came from. Core fields are first-class; corpus-specific
 * fields (speaker, date, topics…) live in `attributes` so new corpora need no schema change.
 */
final class ChunkMetadata
{
    /**
     * @param list<string> $permissions visibility tags, e.g. ['public'] or ['church:42']
     * @param array<string,mixed> $attributes corpus-specific extras (book, chapter, speaker…)
     */
    public function __construct(
        public readonly string $source,          // 'bible' | 'sermon' | 'prayer' | 'policy' | 'document'
        public readonly string $language = 'en',
        public readonly ?string $reference = null, // human citation, e.g. "John 3:16"
        public readonly float $confidence = 1.0,
        public readonly array $permissions = ['public'],
        public readonly array $attributes = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'source'      => $this->source,
            'language'    => $this->language,
            'reference'   => $this->reference,
            'confidence'  => $this->confidence,
            'permissions' => $this->permissions,
            'attributes'  => $this->attributes,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: (string) ($data['source'] ?? 'document'),
            language: (string) ($data['language'] ?? 'en'),
            reference: $data['reference'] ?? null,
            confidence: (float) ($data['confidence'] ?? 1.0),
            permissions: (array) ($data['permissions'] ?? ['public']),
            attributes: (array) ($data['attributes'] ?? []),
        );
    }
}
