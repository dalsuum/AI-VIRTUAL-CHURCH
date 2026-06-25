<?php

namespace App\Services\Knowledge\Store;

use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * In-process vector store with brute-force cosine search. Two uses: (1) the test/dev backend
 * for the whole hybrid pipeline with no infra, and (2) a reference implementation documenting
 * the VectorStore contract. NOT for production scale — QdrantVectorStore is the real backend;
 * because both sit behind VectorStore, nothing else changes when swapping.
 */
final class InMemoryVectorStore implements VectorStore
{
    /** @var array<string,array<string,Chunk>> collection => id => chunk(with embedding) */
    private array $data = [];

    public function upsert(string $collection, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->data[$collection][$chunk->id] = $chunk;
        }
    }

    public function search(string $collection, array $vector, int $k, array $filters = []): array
    {
        $results = [];
        foreach ($this->data[$collection] ?? [] as $chunk) {
            if (! $this->passes($chunk, $filters) || $chunk->embedding === null) {
                continue;
            }
            $results[] = new RetrievedChunk($chunk, $this->cosine($vector, $chunk->embedding), 'vector', $collection);
        }

        usort($results, static fn (RetrievedChunk $a, RetrievedChunk $b) => $b->score <=> $a->score);

        return array_slice($results, 0, $k);
    }

    public function delete(string $collection, array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->data[$collection][$id]);
        }
    }

    /** @param array<string,mixed> $filters */
    private function passes(Chunk $chunk, array $filters): bool
    {
        if (isset($filters['language']) && $chunk->metadata->language !== $filters['language']) {
            return false;
        }
        if (isset($filters['permissions'])) {
            $need = (array) $filters['permissions'];
            if (array_intersect($need, $chunk->metadata->permissions) === []) {
                return false;
            }
        }

        return true;
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
