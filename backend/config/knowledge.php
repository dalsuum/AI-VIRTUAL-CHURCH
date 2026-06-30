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

    // Per-capability RAG toggles. Pastor Chat is relational by default, but with the sermon/KB
    // corpus indexed it can be grounded in the same retrieval pipeline as Bible Study. Flip via
    // env without a code change; only takes effect when `enabled` is true, so dev/test stay
    // ungrounded. Set KNOWLEDGE_PASTOR_RAG=false to restore the old conversational behaviour.
    'capabilities' => [
        'pastor_uses_knowledge' => (bool) env('KNOWLEDGE_PASTOR_RAG', true),
    ],

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
    'corpora' => [
        'bible',
        'bible-meta',
        'reading-plans',
        'docs',
        'faq',
        'sermon',
        'prayer',
        'policy',
        'document',
        'commentary',
        'general',
    ],

    // Ranking nudge only; prompt policy still decides authority. Values are intentionally
    // coarse so Scripture is surfaced early without hiding directly relevant support material.
    'source_priority' => [
        'bible'         => 100,
        'bible-meta'    => 80,
        'docs'          => 70,
        'faq'           => 70,
        'policy'        => 70,
        'reading-plans' => 60,
        'sermon'        => 50,
        'prayer'        => 45,
        'commentary'    => 35,
        'document'      => 30,
        'general'       => 10,
    ],

    'ingestion' => [
        'default_corpora' => [
            'bible-meta'    => storage_path('app/knowledge/bible-meta'),
            'reading-plans' => storage_path('app/knowledge/reading-plans'),
            'docs'          => storage_path('app/knowledge/docs'),
            'faq'           => storage_path('app/knowledge/faq'),
        ],
    ],

    'verification' => [
        'collection' => env('KNOWLEDGE_VERIFY_COLLECTION', 'knowledge_chunks'),
    ],

    'retrieval' => [
        'per_corpus_k'      => (int) env('KNOWLEDGE_PER_CORPUS_K', 8),
        'final_k'           => (int) env('KNOWLEDGE_FINAL_K', 6),
        'rrf_k'             => (int) env('KNOWLEDGE_RRF_K', 60),
        'max_context_chars' => (int) env('KNOWLEDGE_MAX_CONTEXT_CHARS', 4000),
        'lexical_weight'    => (float) env('KNOWLEDGE_LEXICAL_WEIGHT', 0.5),
        'priority_weight'   => (float) env('KNOWLEDGE_PRIORITY_WEIGHT', 0.2),
    ],
];
