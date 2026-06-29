<?php

namespace App\Providers;

use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\Contracts\KeywordIndex;
use App\Services\Knowledge\Contracts\Reranker;
use App\Services\Knowledge\Contracts\VectorStore;
use App\Services\Knowledge\Embedding\HashEmbeddingService;
use App\Services\Knowledge\Embedding\WorkerEmbeddingService;
use App\Services\Knowledge\HybridKnowledgeRetriever;
use App\Services\Knowledge\Ingestion\IngestionPipeline;
use App\Services\Knowledge\Retrieval\ContextBuilder;
use App\Services\Knowledge\Retrieval\HeuristicReranker;
use App\Services\Knowledge\Retrieval\HybridCorpusRetriever;
use App\Services\Knowledge\Retrieval\QueryNormalizer;
use App\Services\Knowledge\Retrieval\ResultMerger;
use App\Services\Knowledge\Retrieval\RetrievalOrchestrator;
use App\Services\Knowledge\Store\InMemoryKeywordIndex;
use App\Services\Knowledge\Store\InMemoryVectorStore;
use App\Services\Knowledge\Store\QdrantKeywordIndex;
use App\Services\Knowledge\Store\QdrantVectorStore;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the Knowledge Platform. Drivers are chosen from config and hidden behind interfaces,
 * so FAISS/worker ↔ Qdrant and hash ↔ worker embeddings swap with no change above this layer.
 *
 * The Chat Orchestrator's KnowledgeRetriever seam is rebound to HybridKnowledgeRetriever ONLY
 * when knowledge.enabled is true; otherwise the Null retriever from ChatServiceProvider stands,
 * keeping production safe until vector infra exists. This provider registers AFTER
 * ChatServiceProvider so its binding wins when enabled.
 */
final class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmbeddingService::class, function ($app) {
            $cfg = config('knowledge.embedding');

            return $cfg['driver'] === 'worker'
                ? new WorkerEmbeddingService($app->make(Http::class), $cfg['worker_url'], (int) $cfg['dimensions'])
                : new HashEmbeddingService((int) $cfg['dimensions']);
        });

        $this->app->singleton(VectorStore::class, function ($app) {
            $cfg = config('knowledge.vector');

            return $cfg['driver'] === 'qdrant'
                ? new QdrantVectorStore($app->make(Http::class), $cfg['qdrant']['url'], $cfg['qdrant']['key'])
                : new InMemoryVectorStore();
        });

        // Keyword branch must persist when vectors do — back it by Qdrant's full-text index so
        // hybrid search works across processes; in-memory only for the dev/memory driver.
        $this->app->singleton(KeywordIndex::class, function ($app) {
            $cfg = config('knowledge.vector');

            return $cfg['driver'] === 'qdrant'
                ? new QdrantKeywordIndex($app->make(Http::class), $cfg['qdrant']['url'], $cfg['qdrant']['key'])
                : new InMemoryKeywordIndex();
        });
        $this->app->singleton(Reranker::class, fn () => new HeuristicReranker(
            lexicalWeight: (float) config('knowledge.retrieval.lexical_weight', 0.5),
            sourcePriority: (array) config('knowledge.source_priority', []),
            priorityWeight: (float) config('knowledge.retrieval.priority_weight', 0.2),
        ));
        $this->app->singleton(QueryNormalizer::class);
        $this->app->singleton(ResultMerger::class, fn () => new ResultMerger((int) config('knowledge.retrieval.rrf_k', 60)));
        $this->app->singleton(ContextBuilder::class, fn () => new ContextBuilder((int) config('knowledge.retrieval.max_context_chars', 4000)));

        $this->app->singleton(RetrievalOrchestrator::class, function ($app) {
            $corpora = array_map(
                fn (string $corpus) => new HybridCorpusRetriever(
                    $corpus,
                    $app->make(KeywordIndex::class),
                    $app->make(VectorStore::class),
                    $app->make(EmbeddingService::class),
                    $app->make(ResultMerger::class),
                ),
                (array) config('knowledge.corpora', []),
            );

            return new RetrievalOrchestrator(
                corpora: $corpora,
                normalizer: $app->make(QueryNormalizer::class),
                merger: $app->make(ResultMerger::class),
                reranker: $app->make(Reranker::class),
                perCorpusK: (int) config('knowledge.retrieval.per_corpus_k', 8),
                finalK: (int) config('knowledge.retrieval.final_k', 6),
                tracer: $app->make(\App\Services\Observability\Contracts\Tracer::class),
            );
        });

        $this->app->singleton(IngestionPipeline::class, fn ($app) => new IngestionPipeline(
            $app->make(EmbeddingService::class),
            $app->make(VectorStore::class),
        ));

        $this->app->singleton(HybridKnowledgeRetriever::class, fn ($app) => new HybridKnowledgeRetriever(
            $app->make(RetrievalOrchestrator::class),
            $app->make(ContextBuilder::class),
            $app->make(LoggerInterface::class),
            $app->make(\App\Services\Observability\Contracts\Tracer::class),
        ));

        // Activate RAG only when configured; otherwise leave the Null retriever in place.
        if (config('knowledge.enabled')) {
            $this->app->singleton(KnowledgeRetriever::class, fn ($app) => $app->make(HybridKnowledgeRetriever::class));
        }
    }
}
