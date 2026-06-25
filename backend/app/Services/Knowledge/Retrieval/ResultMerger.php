<?php

namespace App\Services\Knowledge\Retrieval;

use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * Fuses keyword + vector (and multi-corpus) result lists into one ranked, de-duplicated list
 * using Reciprocal Rank Fusion (RRF). RRF is rank-based, so it combines scores from different
 * scales (BM25 vs. cosine) without fragile normalisation — the standard, robust choice for
 * hybrid retrieval. Duplicates (same chunk id from multiple sources) have their RRF
 * contributions summed, so agreement across methods is rewarded.
 */
final class ResultMerger
{
    public function __construct(private readonly int $k = 60) {}

    /**
     * @param list<list<RetrievedChunk>> $lists each already ranked best-first
     * @return list<RetrievedChunk> fused, de-duplicated, best-first
     */
    public function fuse(array $lists): array
    {
        /** @var array<string,float> $scores */
        $scores = [];
        /** @var array<string,RetrievedChunk> $byId */
        $byId = [];

        foreach ($lists as $list) {
            $rank = 0;
            foreach (array_values($list) as $item) {
                // Isolate corrupt data: a chunk with no id or no text is dropped here so it can
                // never reach dedup, rerank or the prompt. Invalid items don't consume a rank.
                if ($item->chunk->id === '' || trim($item->chunk->text) === '') {
                    continue;
                }
                $id = $item->chunk->id;
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($this->k + $rank + 1);
                // Keep the first-seen representation (highest-ranked occurrence).
                $byId[$id] ??= $item;
                $rank++;
            }
        }

        arsort($scores);

        $fused = [];
        foreach ($scores as $id => $score) {
            $fused[] = $byId[$id]->withScore($score);
        }

        return $fused;
    }
}
