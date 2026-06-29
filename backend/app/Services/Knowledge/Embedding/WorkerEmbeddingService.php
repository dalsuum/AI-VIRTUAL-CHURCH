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

        $payload = $this->http
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/knowledge/embed", ['texts' => array_values($texts)])
            ->throw()
            ->json() ?? [];

        $vectors = $payload['vectors'] ?? $payload['embeddings'] ?? [];

        return array_map(function ($v) {
            $vector = array_map('floatval', (array) $v);
            if (count($vector) !== $this->dimensions) {
                throw new \RuntimeException("Embedding worker returned " . count($vector) . " dimensions; expected {$this->dimensions}.");
            }

            return $vector;
        }, $vectors);
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
