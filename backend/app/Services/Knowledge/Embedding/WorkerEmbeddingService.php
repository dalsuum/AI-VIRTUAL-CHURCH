<?php

namespace App\Services\Knowledge\Embedding;

use App\Services\Knowledge\Contracts\EmbeddingService;
use Illuminate\Http\Client\Factory as Http;

/**
 * Production embedding adapter: calls the Python worker's embedding endpoint (the model runs
 * there, next to Ollama/FAISS). The PHP side stays model-agnostic. Keys/secrets are never sent;
 * the worker owns model config. Batches in one request to amortise latency during ingestion.
 */
final class WorkerEmbeddingService implements EmbeddingService
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly int $dimensions = 768,
        private readonly int $timeout = 120,
        private readonly string $modelTag = 'worker:1',
    ) {}

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $vectors = $this->http
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/knowledge/embed", ['texts' => array_values($texts)])
            ->throw()
            ->json('vectors', []);

        return array_map(static fn ($v) => array_map('floatval', (array) $v), $vectors);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return $this->modelTag;
    }
}
