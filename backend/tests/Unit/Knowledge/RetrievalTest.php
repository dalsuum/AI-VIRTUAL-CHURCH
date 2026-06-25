<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\Data\Chunk;
use App\Services\Knowledge\Data\ChunkMetadata;
use App\Services\Knowledge\Data\Document;
use App\Services\Knowledge\Data\RetrievedChunk;
use App\Services\Knowledge\Embedding\HashEmbeddingService;
use App\Services\Knowledge\Ingestion\BibleVerseChunker;
use App\Services\Knowledge\Ingestion\IngestionPipeline;
use App\Services\Knowledge\Retrieval\ContextBuilder;
use App\Services\Knowledge\Retrieval\HeuristicReranker;
use App\Services\Knowledge\Retrieval\HybridCorpusRetriever;
use App\Services\Knowledge\Retrieval\QueryNormalizer;
use App\Services\Knowledge\Retrieval\ResultMerger;
use App\Services\Knowledge\Retrieval\RetrievalOrchestrator;
use App\Services\Knowledge\Store\InMemoryKeywordIndex;
use App\Services\Knowledge\Store\InMemoryVectorStore;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the Knowledge Platform: RRF fusion, context budgeting, Bible chunking,
 * and a fully-offline end-to-end hybrid retrieval (in-memory store + hash embeddings). No infra.
 */
class RetrievalTest extends TestCase
{
    private function chunk(string $id, string $text, string $ref = null): Chunk
    {
        return new Chunk($id, $text, new ChunkMetadata('bible', 'en', $ref));
    }

    public function test_rrf_rewards_agreement_across_lists(): void
    {
        $a = $this->chunk('a', 'alpha');
        $b = $this->chunk('b', 'beta');
        $keyword = [new RetrievedChunk($a, 5.0, 'keyword', 'bible'), new RetrievedChunk($b, 1.0, 'keyword', 'bible')];
        $vector  = [new RetrievedChunk($b, 0.9, 'vector', 'bible'), new RetrievedChunk($a, 0.1, 'vector', 'bible')];

        $fused = (new ResultMerger(60))->fuse([$keyword, $vector]);

        $this->assertCount(2, $fused, 'duplicates de-duplicated by id');
        $ids = array_map(fn (RetrievedChunk $c) => $c->chunk->id, $fused);
        $this->assertEqualsCanonicalizing(['a', 'b'], $ids);
    }

    public function test_context_builder_respects_char_budget(): void
    {
        $ranked = [
            new RetrievedChunk($this->chunk('1', str_repeat('x', 30), 'John 3:16'), 1.0, 'vector', 'bible'),
            new RetrievedChunk($this->chunk('2', str_repeat('y', 30), 'John 3:17'), 0.9, 'vector', 'bible'),
        ];

        $ctx = (new ContextBuilder(maxChars: 40))->build($ranked);

        $this->assertCount(1, $ctx->snippets, 'second snippet exceeds the budget');
        $this->assertSame('John 3:16', $ctx->snippets[0]['source']);
    }

    public function test_bible_verse_chunker_emits_one_chunk_per_verse_with_reference(): void
    {
        $doc = new Document('gen1', '1 In the beginning God created. 2 The earth was formless.', new ChunkMetadata(
            source: 'bible', language: 'en', attributes: ['book' => 'Genesis', 'chapter' => 1],
        ));

        $chunks = (new BibleVerseChunker())->chunk($doc);

        $this->assertCount(2, $chunks);
        $this->assertSame('Genesis 1:1', $chunks[0]->metadata->reference);
        $this->assertSame('Genesis 1:2', $chunks[1]->metadata->reference);
        $this->assertStringContainsString('beginning', $chunks[0]->text);
    }

    public function test_context_builder_reports_provenance_and_confidence(): void
    {
        $empty = (new ContextBuilder())->build([]);
        $this->assertSame(\App\Services\Chat\Data\KnowledgeContext::EMPTY_NO_MATCH, $empty->reason);
        $this->assertFalse($empty->failedToRetrieve());

        $populated = (new ContextBuilder())->build([
            new RetrievedChunk($this->chunk('1', 'God so loved the world', 'John 3:16'), 0.87, 'vector', 'bible'),
        ]);
        $this->assertSame(\App\Services\Chat\Data\KnowledgeContext::POPULATED, $populated->reason);
        $this->assertSame(0.87, $populated->confidence);
    }

    public function test_failure_context_is_distinct_from_no_match(): void
    {
        $failure = \App\Services\Chat\Data\KnowledgeContext::failure();
        $noMatch = \App\Services\Chat\Data\KnowledgeContext::noMatch();

        $this->assertTrue($failure->failedToRetrieve());
        $this->assertFalse($noMatch->failedToRetrieve());
        $this->assertTrue($failure->isEmpty());
        $this->assertTrue($noMatch->isEmpty(), 'both are empty, but for different reasons');
    }

    public function test_end_to_end_hybrid_retrieval_offline(): void
    {
        $embeddings = new HashEmbeddingService(128);
        $vectors = new InMemoryVectorStore();
        $keyword = new InMemoryKeywordIndex();
        $merger = new ResultMerger(60);

        // Ingest a tiny Bible corpus through the real pipeline.
        $doc = new Document('john3', '16 For God so loved the world that he gave his only Son. 17 God did not send the Son to condemn.', new ChunkMetadata(
            source: 'bible', language: 'en', attributes: ['book' => 'John', 'chapter' => 3],
        ));
        $pipeline = new IngestionPipeline($embeddings, $vectors);
        $count = $pipeline->ingest('bible', [$doc], new BibleVerseChunker(), $keyword);
        $this->assertSame(2, $count);

        $orchestrator = new RetrievalOrchestrator(
            corpora: [new HybridCorpusRetriever('bible', $keyword, $vectors, $embeddings, $merger)],
            normalizer: new QueryNormalizer(),
            merger: $merger,
            reranker: new HeuristicReranker(),
            perCorpusK: 5,
            finalK: 3,
        );

        $outcome = $orchestrator->retrieve('God loved the world', ['language' => 'en']);
        $this->assertFalse($outcome->failed);
        $ctx = (new ContextBuilder())->build($outcome->chunks);

        $this->assertNotEmpty($ctx->snippets);
        $this->assertSame('John 3:16', $ctx->snippets[0]['source'], 'most relevant verse ranked first');
    }
}
