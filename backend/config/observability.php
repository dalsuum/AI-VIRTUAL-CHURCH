<?php

/**
 * Observability layer. Tracing is OFF by default (NullTracer, zero overhead). When enabled, each
 * chat request emits one trace tree (chat.request → guardrails/retrieval/inference/…), persisted
 * by correlation id for the debug endpoint to materialise.
 *
 * Sampling keeps production cost bounded: 100% in dev, a few percent in prod.
 */
return [
    'tracing' => [
        'enabled'     => (bool) env('OBSERVABILITY_TRACING', false),
        'sample_rate' => (float) env('OBSERVABILITY_SAMPLE_RATE', 1.0), // 0..1
        'ttl'         => (int) env('TRACE_TTL', 86400),
        'redis_connection' => env('TRACE_REDIS_CONNECTION', 'default'),
    ],

    // Debug endpoint is staff-only and reads stored traces only (never re-runs retrieval).
    'debug_endpoint' => (bool) env('OBSERVABILITY_DEBUG_ENDPOINT', true),
];
