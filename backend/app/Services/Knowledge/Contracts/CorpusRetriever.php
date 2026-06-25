<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\Data\CorpusResult;

/**
 * Retrieves from ONE corpus (Bible, sermons, prayers, policies, uploaded documents). The
 * Retrieval Orchestrator fans a query across all enabled corpus retrievers and fuses the
 * results, so adding a corpus = registering one more CorpusRetriever — no caller changes.
 *
 * A corpus retriever MUST be resilient: it never throws for a backend failure. Instead it
 * returns a CorpusResult whose error flags record which branch (vector/keyword) was down, so a
 * single dependency outage degrades retrieval rather than failing the chat turn.
 */
interface CorpusRetriever
{
    /** Corpus key, e.g. 'bible'. Used for routing, filtering and metrics. */
    public function corpus(): string;

    /** @param array<string,mixed> $filters */
    public function retrieve(string $query, int $k, array $filters = []): CorpusResult;
}
