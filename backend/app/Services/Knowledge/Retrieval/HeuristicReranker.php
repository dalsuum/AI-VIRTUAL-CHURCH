<?php

namespace App\Services\Knowledge\Retrieval;

use App\Services\Knowledge\Contracts\Reranker;
use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * Default reranker: blends the fused retrieval score with lexical query-term coverage so
 * chunks that actually contain the asked-about terms rise to the top. Cheap, deterministic,
 * dependency-free — a solid baseline that a worker-hosted cross-encoder can later replace
 * behind the Reranker interface.
 */
final class HeuristicReranker implements Reranker
{
    /**
     * @param array<string,int|float> $sourcePriority
     */
    public function __construct(
        private readonly float $lexicalWeight = 0.5,
        private readonly array $sourcePriority = [],
        private readonly float $priorityWeight = 0.2,
    ) {}

    public function rerank(string $query, array $candidates): array
    {
        $terms = $this->terms($query);

        $scored = array_map(function (RetrievedChunk $c) use ($terms) {
            $coverage = $this->coverage($terms, $c->chunk->text);
            $priority = $this->sourcePriority[$c->chunk->metadata->source]
                ?? $this->sourcePriority[$c->corpus]
                ?? 0;

            return $c->withScore($c->score
                + $this->lexicalWeight * $coverage
                + $this->priorityWeight * max(0.0, min(1.0, ((float) $priority) / 100.0)));
        }, $candidates);

        usort($scored, static fn (RetrievedChunk $a, RetrievedChunk $b) => $b->score <=> $a->score);

        return $scored;
    }

    /** @param list<string> $terms */
    private function coverage(array $terms, string $text): float
    {
        if ($terms === []) {
            return 0.0;
        }
        $haystack = mb_strtolower($text);
        $hits = 0;
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        return $hits / count($terms);
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($query), $m);

        return array_values(array_unique($m[0] ?? []));
    }
}
