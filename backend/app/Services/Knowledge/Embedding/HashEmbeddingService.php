<?php

namespace App\Services\Knowledge\Embedding;

use App\Services\Knowledge\Contracts\EmbeddingService;

/**
 * Deterministic, offline embedding via feature hashing (the "hashing trick"): tokens are
 * hashed into a fixed-dimension bag-of-words vector and L2-normalised. It needs no model and
 * no network, so the whole hybrid pipeline runs in tests and local dev, and shared vocabulary
 * yields cosine similarity. Production uses WorkerEmbeddingService (a real model) behind the
 * same interface — vectors stay in one space per deployment because ingestion and query use
 * the SAME bound implementation.
 */
final class HashEmbeddingService implements EmbeddingService
{
    public function __construct(private readonly int $dimensions = 256) {}

    public function embed(array $texts): array
    {
        return array_map(fn (string $t) => $this->vector($t), array_values($texts));
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return "hash-v1:{$this->dimensions}";
    }

    /** @return list<float> */
    private function vector(string $text): array
    {
        $vec = array_fill(0, $this->dimensions, 0.0);
        preg_match_all('/\p{L}{2,}/u', mb_strtolower($text), $m);

        foreach ($m[0] ?? [] as $token) {
            $bucket = (int) (hexdec(substr(md5($token), 0, 8)) % $this->dimensions);
            $vec[$bucket] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $vec)));
        if ($norm > 0) {
            $vec = array_map(static fn ($x) => $x / $norm, $vec);
        }

        return $vec;
    }
}
