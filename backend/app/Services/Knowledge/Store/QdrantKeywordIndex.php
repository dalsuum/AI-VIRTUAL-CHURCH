<?php

namespace App\Services\Knowledge\Store;

use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\RetrievedChunk;
use Illuminate\Http\Client\Factory as Http;

/**
 * Persistent keyword branch backed by Qdrant's full-text payload index on the chunk `text`
 * field (created by QdrantVectorStore::ensureCollection). It reuses the SAME points the vector
 * branch stores — no second datastore — so hybrid retrieval works across processes (the gap the
 * in-memory keyword index had). Qdrant full-text match is a filter, not a relevance score, so
 * results are rank-ordered; RRF fusion is rank-based anyway, so this composes cleanly.
 */
final class QdrantKeywordIndex implements KeywordIndex
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

    public function search(string $collection, string $query, int $k, array $filters = []): array
    {
        $must = [['key' => 'text', 'match' => ['text' => $query]]];
        if (isset($filters['language'])) {
            $must[] = ['key' => 'language', 'match' => ['value' => $filters['language']]];
        }
        if (isset($filters['permissions'])) {
            $must[] = ['key' => 'permissions', 'match' => ['any' => (array) $filters['permissions']]];
        }

        try {
            $points = $this->request('post', "/collections/{$collection}/points/scroll", [
                'filter'       => ['must' => $must],
                'limit'        => $k,
                'with_payload' => true,
            ])['result']['points'] ?? [];
        } catch (\Throwable) {
            return []; // resilient: a keyword outage is a degraded branch, never an exception
        }

        $out = [];
        foreach (array_values($points) as $rank => $point) {
            $p = $point['payload'] ?? [];
            $chunk = new Chunk(
                (string) ($p['chunk_id'] ?? $point['id']),
                (string) ($p['text'] ?? ''),
                ChunkMetadata::fromArray((array) ($p['metadata'] ?? [])),
            );
            // Descending pseudo-score by position so the fusion sees a stable ranking.
            $out[] = new RetrievedChunk($chunk, 1.0 / ($rank + 1), 'keyword', $collection);
        }

        return $out;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function request(string $method, string $path, array $body): array
    {
        $req = $this->http->timeout($this->timeout);
        if ($this->apiKey) {
            $req = $req->withHeaders(['api-key' => $this->apiKey]);
        }

        return $req->{$method}("{$this->baseUrl}{$path}", $body)->throw()->json() ?? [];
    }
}
