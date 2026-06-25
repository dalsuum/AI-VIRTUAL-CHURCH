<?php

namespace App\Services\Observability\Store;

use App\Services\Observability\Contracts\TraceStore;

/**
 * In-process trace store for tests and local dev — same contract as RedisTraceStore, no infra.
 */
final class ArrayTraceStore implements TraceStore
{
    /** @var array<string,array<string,mixed>> */
    private array $traces = [];

    public function put(string $correlationId, array $trace): void
    {
        $this->traces[$correlationId] = $trace;
    }

    public function get(string $correlationId): ?array
    {
        return $this->traces[$correlationId] ?? null;
    }
}
