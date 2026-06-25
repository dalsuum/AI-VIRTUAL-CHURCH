<?php

namespace App\Services\Knowledge\Store;

use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\RetrievedChunk;
use Illuminate\Http\Client\Factory as Http;

/**
 * Production vector backend (Phase 2). Stores each chunk as a Qdrant point: vector + a payload
 * carrying the text and full metadata, so search reconstructs a Chunk without a second DB hop.
 * Payload filtering (language, permissions, corpus, church) is pushed DOWN to Qdrant for
 * correctness and speed — the per-tenant scoping the metadata design was built for.
 *
 * Collections map 1:1 to corpora. The API key (if any) is injected, never logged.
 */
final class QdrantVectorStore implements VectorStore
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 30,
    ) {}

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
