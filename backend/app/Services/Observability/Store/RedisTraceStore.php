<?php

namespace App\Services\Observability\Store;

use App\Services\Observability\Contracts\TraceStore;
use Illuminate\Contracts\Redis\Factory as Redis;

/**
 * Redis-backed trace store with a bounded TTL — traces are debugging breadcrumbs, not durable
 * records, so they expire automatically. Keyed by correlation id. Stored as JSON; never contains
 * chunk text or secrets (the Span attribute discipline guarantees this upstream).
 */
final class RedisTraceStore implements TraceStore
{
    public function __construct(
        private readonly Redis $redis,
        private readonly int $ttlSeconds = 86400,
        private readonly string $connection = 'default',
    ) {}

    public function put(string $correlationId, array $trace): void
    {
        $this->redis->connection($this->connection)
            ->setex($this->key($correlationId), $this->ttlSeconds, json_encode($trace, JSON_UNESCAPED_UNICODE));
    }

    public function get(string $correlationId): ?array
    {
        $raw = $this->redis->connection($this->connection)->get($this->key($correlationId));
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function key(string $correlationId): string
    {
        return "trace:{$correlationId}";
    }
}
