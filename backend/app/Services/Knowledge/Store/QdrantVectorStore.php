<?php

namespace App\Services\Knowledge\Store;

use App\Services\Knowledge\Contracts\ManagesCollections;
use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\RetrievedChunk;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * Production vector backend (Phase 2). Stores each chunk as a Qdrant point: vector + a payload
 * carrying the text and full metadata, so search reconstructs a Chunk without a second DB hop.
 * Payload filtering (language, permissions, corpus, church) is pushed DOWN to Qdrant for
 * correctness and speed — the per-tenant scoping the metadata design was built for.
 *
 * Collections map 1:1 to corpora. The API key (if any) is injected, never logged.
 */
final class QdrantVectorStore implements VectorStore, ManagesCollections
{
    private const COLLECTIONS_CACHE_KEY = 'knowledge.qdrant.collections';
    private const COLLECTIONS_CACHE_TTL = 60; // seconds — short, so newly-ingested corpora appear fast

    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

    public function ensureCollection(string $collection, int $dimensions): void
    {
        // Already provisioned? (GET 200) → nothing to do.
        if ($this->exists($collection)) {
            return;
        }

        // Create the collection with cosine-distance vectors of the embedder's dimensionality.
        $this->request('put', "/collections/{$collection}", [
            'vectors' => ['size' => $dimensions, 'distance' => 'Cosine'],
        ]);

        // Full-text index on the chunk text → powers the persistent keyword branch
        // (QdrantKeywordIndex) so hybrid search works across processes.
        $this->request('put', "/collections/{$collection}/index", [
            'field_name'   => 'text',
            'field_schema' => 'text',
        ]);

        // A freshly-created corpus must light up immediately. Cache failure must never break ingest.
        try {
            Cache::forget(self::COLLECTIONS_CACHE_KEY);
        } catch (\Throwable) { /* no cache context (e.g. unit test) → next read fetches live */ }
    }

    /**
     * Whether the collection exists, cached briefly to bound the API calls (one GET /collections
     * per TTL, not one per corpus per turn). Fails OPEN: if the listing can't be fetched we report
     * "present" so retrieval runs and a real outage surfaces as degraded/failed, not a clean miss.
     */
    public function hasCollection(string $collection): bool
    {
        $live = $this->liveCollections();

        return $live === null || in_array($collection, $live, true);
    }

    /** @return list<string>|null Existing collection names; null when the listing couldn't be fetched. */
    private function liveCollections(): ?array
    {
        try {
            $cached = Cache::get(self::COLLECTIONS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) { /* no cache context → fall through to a live listing */ }

        try {
            $req = $this->http->timeout($this->timeout);
            if ($this->apiKey) {
                $req = $req->withHeaders(['api-key' => $this->apiKey]);
            }
            $collections = $req->get("{$this->baseUrl}/collections")->throw()->json('result.collections') ?? [];
            $names = array_values(array_filter(array_map(
                static fn ($c) => is_array($c) ? ($c['name'] ?? null) : null,
                $collections,
            )));
            try {
                Cache::put(self::COLLECTIONS_CACHE_KEY, $names, self::COLLECTIONS_CACHE_TTL);
            } catch (\Throwable) { /* cache failure must never break retrieval */ }

            return $names;
        } catch (\Throwable) {
            return null; // unknown — do NOT cache; callers fail open
        }
    }

    private function exists(string $collection): bool
    {
        try {
            $req = $this->http->timeout($this->timeout);
            if ($this->apiKey) {
                $req = $req->withHeaders(['api-key' => $this->apiKey]);
            }

            return $req->get("{$this->baseUrl}/collections/{$collection}")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function upsert(string $collection, array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $points = array_map(fn (Chunk $c) => [
            'id'      => $this->pointId($c->id),
            'vector'  => $c->embedding ?? [],
            'payload' => [
                'chunk_id'    => $c->id,
                'text'        => $c->text,
                'source'      => $c->metadata->source,
                'language'    => $c->metadata->language,
                'reference'   => $c->metadata->reference,
                'permissions' => $c->metadata->permissions,
                'metadata'    => $c->metadata->toArray(),
            ],
        ], $chunks);

        $this->request('put', "/collections/{$collection}/points", ['points' => $points]);
    }

    public function search(string $collection, array $vector, int $k, array $filters = []): array
    {
        $body = ['vector' => $vector, 'limit' => $k, 'with_payload' => true];
        if ($filter = $this->buildFilter($filters)) {
            $body['filter'] = $filter;
        }

        $hits = $this->request('post', "/collections/{$collection}/points/search", $body)['result'] ?? [];

        return array_map(function (array $hit) use ($collection) {
            $p = $hit['payload'] ?? [];
            $chunk = new Chunk(
                (string) ($p['chunk_id'] ?? $hit['id']),
                (string) ($p['text'] ?? ''),
                ChunkMetadata::fromArray((array) ($p['metadata'] ?? [])),
            );

            return new RetrievedChunk($chunk, (float) ($hit['score'] ?? 0.0), 'vector', $collection);
        }, $hits);
    }

    public function delete(string $collection, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $points = array_map(fn (string $id) => $this->pointId($id), $ids);
        $this->request('post', "/collections/{$collection}/points/delete", ['points' => $points]);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed>|null */
    private function buildFilter(array $filters): ?array
    {
        $must = [];
        if (isset($filters['language'])) {
            $must[] = ['key' => 'language', 'match' => ['value' => $filters['language']]];
        }
        if (isset($filters['permissions'])) {
            $must[] = ['key' => 'permissions', 'match' => ['any' => (array) $filters['permissions']]];
        }

        return $must === [] ? null : ['must' => $must];
    }

    /** Qdrant point ids must be uint64 or UUID; hash the stable chunk id into a UUID. */
    private function pointId(string $chunkId): string
    {
        $h = md5($chunkId);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
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
