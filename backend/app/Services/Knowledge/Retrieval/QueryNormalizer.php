<?php

namespace App\Services\Knowledge\Retrieval;

/**
 * First retrieval stage: trims/normalises whitespace and case-folds for keyword matching, and
 * exposes a light, deterministic expansion hook. Kept intentionally minimal and offline; a
 * worker-hosted LLM query-expander can later enrich this without changing the orchestrator.
 */
final class QueryNormalizer
{
    public function normalize(string $query): string
    {
        $q = preg_replace('/\s+/u', ' ', trim($query)) ?? $query;

        return $q;
    }
}
