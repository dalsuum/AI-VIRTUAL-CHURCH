<?php

namespace App\Services\Observability\Contracts;

/**
 * Persists completed traces by correlation id so the debug endpoint can MATERIALISE a request's
 * execution graph from recorded telemetry — never by re-running retrieval (which would drift from
 * what actually happened under load). Backed by Redis with a TTL in production.
 */
interface TraceStore
{
    /** @param array<string,mixed> $trace serialised trace tree */
    public function put(string $correlationId, array $trace): void;

    /** @return array<string,mixed>|null */
    public function get(string $correlationId): ?array;
}
