<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\Store\QdrantKeywordIndex;
use App\Services\Knowledge\Store\QdrantVectorStore;
use Illuminate\Http\Client\Factory as Http;
use PHPUnit\Framework\TestCase;

/**
 * Qdrant adapter tests with a faked HTTP client — no live Qdrant. Verifies collection
 * provisioning (create when absent), and that keyword-scroll results are parsed back into
 * RetrievedChunks with provenance restored from the payload.
 */
class QdrantStoreTest extends TestCase
{
    public function test_ensure_collection_creates_when_absent(): void
    {
        $http = new Http();
        // GET existence check → 404 (absent); subsequent PUTs (create + index) → 200.
        $http->fake([
            '*/collections/sermon' => $http->sequence()
                ->push([], 404)            // GET exists? no
                ->push(['result' => true]) // PUT create
                ->push(['result' => true]),
            '*' => $http->response(['result' => true], 200),
        ]);

        (new QdrantVectorStore($http, 'http://qd:6333'))->ensureCollection('sermon', 384);

        $http->assertSent(fn ($req) => $req->method() === 'PUT'
            && str_contains($req->url(), '/collections/sermon')
            && ($req->data()['vectors']['size'] ?? null) === 384);
        $http->assertSent(fn ($req) => str_contains($req->url(), '/collections/sermon/index')
            && ($req->data()['field_schema'] ?? null) === 'text');
    }

    public function test_ensure_collection_skips_when_present(): void
    {
        $http = new Http();
        $http->fake(['*/collections/sermon' => $http->response(['result' => ['status' => 'green']], 200)]);

        (new QdrantVectorStore($http, 'http://qd:6333'))->ensureCollection('sermon', 384);

        // Only the GET existence check — no create PUT.
        $http->assertNotSent(fn ($req) => $req->method() === 'PUT');
    }

    public function test_keyword_search_parses_scroll_payload(): void
    {
        $http = new Http();
        $http->fake([
            '*/points/scroll' => $http->response([
                'result' => ['points' => [
                    ['id' => 'p1', 'payload' => [
                        'chunk_id' => 'sermon:grace:3',
                        'text'     => 'grace abounds to the humble',
                        'metadata' => ['source' => 'sermon', 'language' => 'en', 'reference' => 'Grace'],
                    ]],
                ]],
            ], 200),
        ]);

        $hits = (new QdrantKeywordIndex($http, 'http://qd:6333'))->search('sermon', 'grace', 5, ['language' => 'en']);

        $this->assertCount(1, $hits);
        $this->assertSame('sermon:grace:3', $hits[0]->chunk->id);
        $this->assertSame('keyword', $hits[0]->method);
        $this->assertSame('sermon', $hits[0]->chunk->metadata->source);
        $http->assertSent(fn ($req) => str_contains($req->url(), '/points/scroll')
            && isset($req->data()['filter']['must']));
    }

    public function test_keyword_search_is_resilient_to_qdrant_errors(): void
    {
        $http = new Http();
        $http->fake(['*' => $http->response('boom', 500)]);

        $hits = (new QdrantKeywordIndex($http, 'http://qd:6333'))->search('sermon', 'grace', 5);

        $this->assertSame([], $hits, 'a keyword backend error degrades to empty, never throws');
    }
}
