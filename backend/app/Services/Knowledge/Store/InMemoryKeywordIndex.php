<?php

namespace App\Services\Knowledge\Store;

use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * In-process lexical index scoring by query-term coverage. Pairs with InMemoryVectorStore for
 * a fully-offline hybrid pipeline in tests/dev. The production keyword path is a DB FULLTEXT or
 * a worker-side BM25 index implementing this same interface — callers are unaffected.
 */
final class InMemoryKeywordIndex implements KeywordIndex
{
    /** @var array<string,list<Chunk>> collection => chunks */
    private array $data = [];

    /** @param list<Chunk> $chunks */
    public function seed(string $collection, array $chunks): void
    {
        $this->data[$collection] = array_merge($this->data[$collection] ?? [], $chunks);
    }

    public function search(string $collection, string $query, int $k, array $filters = []): array
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return [];
        }

        $results = [];
        foreach ($this->data[$collection] ?? [] as $chunk) {
            if (! $this->passes($chunk, $filters)) {
                continue;
            }
            $score = $this->score($terms, $chunk->text);
            if ($score > 0) {
                $results[] = new RetrievedChunk($chunk, $score, 'keyword', $collection);
            }
        }

        usort($results, static fn (RetrievedChunk $a, RetrievedChunk $b) => $b->score <=> $a->score);

        return array_slice($results, 0, $k);
    }

    /** @param list<string> $terms */
    private function score(array $terms, string $text): float
    {
        $haystack = mb_strtolower($text);
        $hits = 0;
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        return $hits / count($terms);
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

    /** @return list<string> */
    private function terms(string $query): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($query), $m);

        return array_values(array_unique($m[0] ?? []));
    }
}
