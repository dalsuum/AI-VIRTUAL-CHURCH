<?php

namespace Tests\Unit\Knowledge;

use App\Services\Chat\Data\KnowledgeContext;
use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\RetrievedChunk;
use App\Services\Knowledge\Embedding\HashEmbeddingService;
use App\Services\Knowledge\HybridKnowledgeRetriever;
use App\Services\Knowledge\Retrieval\ContextBuilder;
use App\Services\Knowledge\Retrieval\HeuristicReranker;
use App\Services\Knowledge\Retrieval\HybridCorpusRetriever;
use App\Services\Knowledge\Retrieval\QueryNormalizer;
use App\Services\Knowledge\Retrieval\ResultMerger;
use App\Services\Knowledge\Retrieval\RetrievalOrchestrator;
use App\Services\Knowledge\Store\InMemoryKeywordIndex;
use App\Services\Knowledge\Store\InMemoryVectorStore;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The failure CONTRACT suite: proves the Knowledge Platform degrades correctly at every
 * boundary. These are invariants, not happy-path tests — each asserts a specific degradation
 * mode and its classification (POPULATED / NO_MATCH / FAILURE).
 */
class FailureModesTest extends TestCase
{
    // ── failure-injection doubles ───────────────────────────────────────────────

    private function throwingVectorStore(): VectorStore
    {
        return new class implements VectorStore {
            public function upsert(string $c, array $chunks): void { throw new \RuntimeException('qdrant down'); }
            public function search(string $c, array $v, int $k, array $f = []): array { throw new \RuntimeException('qdrant down'); }
            public function delete(string $c, array $ids): void {}
        };
    }

    private function throwingEmbeddings(): EmbeddingService
    {
        return new class implements EmbeddingService {
            public function embed(array $texts): array { throw new \RuntimeException('embedding worker unreachable'); }
            public function dimensions(): int { return 256; }
            public function model(): string { return 'broken'; }
        };
    }

    private function throwingKeyword(): KeywordIndex
    {
        return new class implements KeywordIndex {
            public function search(string $c, string $q, int $k, array $f = []): array { throw new \RuntimeException('keyword index down'); }
        };
    }

    private function seededKeyword(): InMemoryKeywordIndex
    {
        $idx = new InMemoryKeywordIndex();
        $idx->seed('bible', [
            new Chunk('bible:john.3.16', 'For God so loved the world', new ChunkMetadata('bible', 'en', 'John 3:16')),
        ]);

        return $idx;
    }

    private function retriever(VectorStore $vectors, KeywordIndex $keyword, EmbeddingService $embeddings): HybridKnowledgeRetriever
    {
        $merger = new ResultMerger(60);
        $orchestrator = new RetrievalOrchestrator(
            corpora: [new HybridCorpusRetriever('bible', $keyword, $vectors, $embeddings, $merger)],
            normalizer: new QueryNormalizer(),
            merger: $merger,
            reranker: new HeuristicReranker(),
        );

        return new HybridKnowledgeRetriever($orchestrator, new ContextBuilder(), new NullLogger());
    }

    // ── 1. Vector store down → keyword carries the turn ─────────────────────────

    public function test_vector_store_down_falls_back_to_keyword(): void
    {
        $ctx = $this->retriever($this->throwingVectorStore(), $this->seededKeyword(), new HashEmbeddingService(64))
            ->retrieve('God loved the world', ['language' => 'en']);

        $this->assertSame(KnowledgeContext::POPULATED, $ctx->reason, 'vector failure must never become chat failure');
        $this->assertNotEmpty($ctx->snippets);
        $this->assertSame('John 3:16', $ctx->snippets[0]['source']);
    }

    // ── 2. Embedding worker down → vector branch disabled, keyword continues ─────

    public function test_embedding_failure_keeps_keyword_path(): void
    {
        $ctx = $this->retriever(new InMemoryVectorStore(), $this->seededKeyword(), $this->throwingEmbeddings())
            ->retrieve('God loved the world', ['language' => 'en']);

        $this->assertSame(KnowledgeContext::POPULATED, $ctx->reason);
        $this->assertNotEmpty($ctx->snippets, 'retrieval remains deterministic when embeddings degrade');
    }

    // ── 3. Empty corpus → NO_MATCH, never FAILURE ───────────────────────────────

    public function test_empty_corpus_is_no_match_not_failure(): void
    {
        $ctx = $this->retriever(new InMemoryVectorStore(), new InMemoryKeywordIndex(), new HashEmbeddingService(64))
            ->retrieve('anything', ['language' => 'en']);

        $this->assertSame(KnowledgeContext::EMPTY_NO_MATCH, $ctx->reason, '"no knowledge" is not "broken system"');
        $this->assertFalse($ctx->failedToRetrieve());
    }

    // ── Total outage → FAILURE (distinct from no-match) ─────────────────────────

    public function test_total_backend_failure_is_classified_failure(): void
    {
        $ctx = $this->retriever($this->throwingVectorStore(), $this->throwingKeyword(), $this->throwingEmbeddings())
            ->retrieve('God loved the world', ['language' => 'en']);

        $this->assertSame(KnowledgeContext::EMPTY_FAILURE, $ctx->reason);
        $this->assertTrue($ctx->failedToRetrieve());
        $this->assertTrue($ctx->isEmpty());
    }

    // ── 4. Partial corruption isolated at merge ─────────────────────────────────

    public function test_invalid_chunks_are_dropped_at_merge(): void
    {
        $valid = new RetrievedChunk(new Chunk('ok', 'real verse text', new ChunkMetadata('bible')), 1.0, 'vector', 'bible');
        $noId  = new RetrievedChunk(new Chunk('', 'orphan text', new ChunkMetadata('bible')), 1.0, 'vector', 'bible');
        $noText = new RetrievedChunk(new Chunk('blank', '   ', new ChunkMetadata('bible')), 1.0, 'vector', 'bible');

        $fused = (new ResultMerger(60))->fuse([[$valid, $noId, $noText]]);

        $this->assertCount(1, $fused, 'corrupt chunks never propagate past merge');
        $this->assertSame('ok', $fused[0]->chunk->id);
    }

    // ── 5. ContextBuilder boundary protection ───────────────────────────────────

    public function test_context_builder_enforces_budget_and_dedup(): void
    {
        $dupA = new RetrievedChunk(new Chunk('a', 'same text', new ChunkMetadata('bible', 'en', 'John 3:16')), 0.9, 'vector', 'bible');
        $dupB = new RetrievedChunk(new Chunk('b', 'same text', new ChunkMetadata('bible', 'en', 'John 3:16')), 0.8, 'vector', 'bible');
        $big  = new RetrievedChunk(new Chunk('c', str_repeat('z', 5000), new ChunkMetadata('bible', 'en', 'Ps 1:1')), 0.7, 'vector', 'bible');

        $ctx = (new ContextBuilder(maxChars: 50))->build([$dupA, $dupB, $big]);

        $this->assertCount(1, $ctx->snippets, 'duplicate reference+text collapsed; budget stops the rest');
        // Only safe projected fields leak into the prompt — never raw store objects.
        $this->assertSame(['source', 'text', 'score'], array_keys($ctx->snippets[0]));
    }

    // ── Failure-classification contract: exactly one reason, from the allowed set ─

    public function test_reason_is_always_a_single_known_classification(): void
    {
        $allowed = [
            KnowledgeContext::POPULATED,
            KnowledgeContext::EMPTY_NO_MATCH,
            KnowledgeContext::EMPTY_FAILURE,
            KnowledgeContext::DISABLED,
        ];

        $cases = [
            $this->retriever($this->throwingVectorStore(), $this->seededKeyword(), new HashEmbeddingService(64))->retrieve('God loved the world', ['language' => 'en']),
            $this->retriever(new InMemoryVectorStore(), new InMemoryKeywordIndex(), new HashEmbeddingService(64))->retrieve('x', ['language' => 'en']),
            $this->retriever($this->throwingVectorStore(), $this->throwingKeyword(), $this->throwingEmbeddings())->retrieve('x', ['language' => 'en']),
            KnowledgeContext::empty(),
        ];

        foreach ($cases as $ctx) {
            $this->assertContains($ctx->reason, $allowed, 'reason must be a known classification');
            // No ambiguous dual-state: FAILURE implies empty; POPULATED implies non-empty.
            if ($ctx->reason === KnowledgeContext::POPULATED) {
                $this->assertNotEmpty($ctx->snippets);
            } else {
                $this->assertTrue($ctx->isEmpty(), "non-populated reason {$ctx->reason} must be empty");
            }
        }
    }
}
