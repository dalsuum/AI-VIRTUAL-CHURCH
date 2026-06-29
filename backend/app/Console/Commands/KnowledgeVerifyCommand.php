<?php

namespace App\Console\Commands;

use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Chat\Support\NullKnowledgeRetriever;
use App\Services\Knowledge\Contracts\EmbeddingService;
use App\Services\Knowledge\HybridKnowledgeRetriever;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;

final class KnowledgeVerifyCommand extends Command
{
    protected $signature = 'knowledge:verify
        {--collection= : Qdrant collection to inspect}
        {--text=John 3:16 : Probe text for embedding determinism}';

    protected $description = 'Verify the Knowledge RAG stack: DI binding, worker embeddings, and Qdrant collection shape';

    private int $failures = 0;
    private int $warnings = 0;

    public function handle(Application $app, EmbeddingService $embeddings, Http $http): int
    {
        $this->line('Knowledge Platform verification');

        $this->verifyBinding($app);
        $this->verifyWorkerHealth($http);
        $this->verifyEmbeddings($embeddings);
        $this->verifyQdrant($http, $embeddings->dimensions());

        if ($this->failures > 0) {
            $this->error("Verification failed with {$this->failures} failure(s) and {$this->warnings} warning(s).");

            return self::FAILURE;
        }

        $this->info("Verification passed with {$this->warnings} warning(s).");

        return self::SUCCESS;
    }

    private function verifyBinding(Application $app): void
    {
        $enabled = (bool) config('knowledge.enabled');
        $retriever = $app->make(KnowledgeRetriever::class);

        if ($enabled && $retriever instanceof HybridKnowledgeRetriever) {
            $this->passLine('Laravel DI uses HybridKnowledgeRetriever.');

            return;
        }

        if (! $enabled && $retriever instanceof NullKnowledgeRetriever) {
            $this->warnLine('KNOWLEDGE_ENABLED=false, so Laravel is still using NullKnowledgeRetriever.');

            return;
        }

        $expected = $enabled ? HybridKnowledgeRetriever::class : NullKnowledgeRetriever::class;
        $this->failLine('KnowledgeRetriever binding mismatch: expected ' . $expected . ', got ' . $retriever::class . '.');
    }

    private function verifyWorkerHealth(Http $http): void
    {
        if (config('knowledge.embedding.driver') !== 'worker') {
            $this->warnLine('KNOWLEDGE_EMBEDDING is not worker; skipping worker health endpoint.');

            return;
        }

        $url = rtrim((string) config('knowledge.embedding.worker_url'), '/') . '/knowledge/health';
        try {
            $response = $http->timeout(10)->get($url);
        } catch (\Throwable $e) {
            $this->failLine("Worker health check failed: {$e->getMessage()}");

            return;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $this->failLine("Worker health check returned HTTP {$response->status()}.");

            return;
        }

        $this->passLine('Worker health endpoint is reachable.');
    }

    private function verifyEmbeddings(EmbeddingService $embeddings): void
    {
        $text = (string) $this->option('text');
        try {
            $vectors = $embeddings->embed([$text, $text]);
        } catch (\Throwable $e) {
            $this->failLine("Embedding probe failed: {$e->getMessage()}");

            return;
        }

        $actual = count($vectors[0] ?? []);
        $expected = $embeddings->dimensions();
        if ($actual !== $expected) {
            $this->failLine("Embedding dimension mismatch: expected {$expected}, got {$actual}.");
        } else {
            $this->passLine("Embedding dimension is {$actual}.");
        }

        if (count($vectors) < 2 || ! $this->sameVector($vectors[0] ?? [], $vectors[1] ?? [])) {
            $this->failLine('Embedding output is not deterministic for the probe text.');
        } else {
            $this->passLine('Embedding output is deterministic for the probe text.');
        }

        if (config('knowledge.embedding.driver') === 'worker' && $actual !== 384) {
            $this->warnLine('Production worker embedding dimension is expected to be 384 for all-MiniLM-L6-v2.');
        }
    }

    private function verifyQdrant(Http $http, int $dimensions): void
    {
        if (config('knowledge.vector.driver') !== 'qdrant') {
            $this->warnLine('KNOWLEDGE_VECTOR is not qdrant; skipping Qdrant collection verification.');

            return;
        }

        $collection = (string) ($this->option('collection') ?: config('knowledge.verification.collection'));
        $baseUrl = rtrim((string) config('knowledge.vector.qdrant.url'), '/');
        $request = $http->timeout(10);
        if ($key = config('knowledge.vector.qdrant.key')) {
            $request = $request->withHeaders(['api-key' => $key]);
        }

        try {
            $response = $request->get("{$baseUrl}/collections/{$collection}");
        } catch (\Throwable $e) {
            $this->failLine("Qdrant collection check failed: {$e->getMessage()}");

            return;
        }

        if (! $response->successful()) {
            $this->failLine("Qdrant collection '{$collection}' is not reachable (HTTP {$response->status()}).");

            return;
        }

        [$size, $distance] = $this->qdrantVectorShape($response->json() ?? []);
        if ($size !== $dimensions) {
            $this->failLine("Qdrant collection '{$collection}' vector size mismatch: expected {$dimensions}, got " . ($size ?? 'unknown') . '.');
        } else {
            $this->passLine("Qdrant collection '{$collection}' vector size is {$size}.");
        }

        if (strtolower((string) $distance) !== 'cosine') {
            $this->failLine("Qdrant collection '{$collection}' distance mismatch: expected Cosine, got " . ($distance ?? 'unknown') . '.');
        } else {
            $this->passLine("Qdrant collection '{$collection}' uses Cosine distance.");
        }
    }

    /** @param list<float> $a @param list<float> $b */
    private function sameVector(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $value) {
            if (abs((float) $value - (float) ($b[$i] ?? null)) > 0.000001) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $payload @return array{0:int|null,1:string|null} */
    private function qdrantVectorShape(array $payload): array
    {
        $vectors = $payload['result']['config']['params']['vectors'] ?? null;
        if (! is_array($vectors)) {
            return [null, null];
        }

        if (array_key_exists('size', $vectors)) {
            return [(int) $vectors['size'], (string) ($vectors['distance'] ?? '')];
        }

        $first = reset($vectors);
        if (is_array($first)) {
            return [
                isset($first['size']) ? (int) $first['size'] : null,
                isset($first['distance']) ? (string) $first['distance'] : null,
            ];
        }

        return [null, null];
    }

    private function passLine(string $message): void
    {
        $this->info("[PASS] {$message}");
    }

    private function warnLine(string $message): void
    {
        $this->warnings++;
        $this->warn("[WARN] {$message}");
    }

    private function failLine(string $message): void
    {
        $this->failures++;
        $this->error("[FAIL] {$message}");
    }
}
