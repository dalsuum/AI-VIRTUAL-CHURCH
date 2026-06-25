<?php

/**
 * Knowledge Platform configuration.
 *
 * `enabled` is the master switch: while false, the Chat Orchestrator keeps using
 * NullKnowledgeRetriever (no RAG), so production is unaffected until the embedding/vector infra
 * is provisioned. Flip to true once a real EmbeddingService + VectorStore are configured, and
 * the Citation/Hallucination/Theology guards begin operating on grounded context.
 *
 * Drivers are swappable behind the layer's interfaces:
 *   embedding.driver : 'hash' (offline/dev) | 'worker' (Python model)
 *   vector.driver    : 'memory' (dev/test)  | 'qdrant' (production)
 */

return [
    'enabled' => (bool) env('KNOWLEDGE_ENABLED', false),

    'embedding' => [
        'driver'     => env('KNOWLEDGE_EMBEDDING', 'hash'),
        'dimensions' => (int) env('KNOWLEDGE_EMBEDDING_DIMS', 256),
        'worker_url' => env('KNOWLEDGE_WORKER_URL', 'http://127.0.0.1:8004'),
    ],

    'vector' => [
        'driver' => env('KNOWLEDGE_VECTOR', 'memory'),
        'qdrant' => [
            'url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),
            'key' => env('QDRANT_API_KEY'),
        ],
    ],

    // Corpora available from day one; each gets its own hybrid retriever + collection.
    'corpora' => ['bible', 'sermon', 'prayer', 'policy', 'document'],

    'retrieval' => [
        'per_corpus_k'      => (int) env('KNOWLEDGE_PER_CORPUS_K', 8),
        'final_k'           => (int) env('KNOWLEDGE_FINAL_K', 6),
        'rrf_k'             => (int) env('KNOWLEDGE_RRF_K', 60),
        'max_context_chars' => (int) env('KNOWLEDGE_MAX_CONTEXT_CHARS', 4000),
    ],
];
