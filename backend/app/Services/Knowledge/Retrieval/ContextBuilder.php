<?php

namespace App\Services\Knowledge\Retrieval;

use App\Services\Chat\Data\KnowledgeContext;
use App\Services\Knowledge\Data\RetrievedChunk;

/**
 * Projects the final ranked chunks into the Chat layer's KnowledgeContext — the ONLY type the
 * Prompt Builder ever sees. This is the boundary that keeps prompt generation deterministic and
 * free of any storage/retrieval knowledge. Applies the token/byte budget so prompts stay bounded.
 *
 * HARD RULE: ContextBuilder is PURE TRANSFORMATION, never policy. It maps chunks → context and
 * reports a provenance signal (POPULATED vs NO_MATCH) and a confidence (top score). It does NOT
 * decide what an empty or low-confidence context MEANS — that judgement belongs to the guards.
 */
final class ContextBuilder
{
    public function __construct(private readonly int $maxChars = 4000) {}

    /** @param list<RetrievedChunk> $ranked already reranked, best-first */
    public function build(array $ranked): KnowledgeContext
    {
        $snippets = [];
        $used = 0;
        $topScore = 0.0;
        $seen = [];

        foreach ($ranked as $item) {
            $text = trim($item->chunk->text);
            if ($text === '') {
                continue; // never trust upstream structure: drop empty
            }
            $source = $item->chunk->metadata->reference ?? $item->chunk->metadata->source;
            $dedupeKey = $source . '|' . md5($text);
            if (isset($seen[$dedupeKey])) {
                continue; // duplicate reference+text collapsed
            }
            if ($used + mb_strlen($text) > $this->maxChars && $snippets !== []) {
                break; // budget reached; keep at least one snippet
            }
            $seen[$dedupeKey] = true;
            $used += mb_strlen($text);
            $topScore = max($topScore, $item->score);

            $snippets[] = [
                'source' => $item->chunk->metadata->reference ?? $item->chunk->metadata->source,
                'text'   => $text,
                'score'  => round($item->score, 4),
            ];
        }

        if ($snippets === []) {
            return KnowledgeContext::noMatch();
        }

        // Confidence is the (clamped) top retrieval score — a transformation, not a decision.
        return KnowledgeContext::populated($snippets, max(0.0, min(1.0, round($topScore, 4))));
    }
}
