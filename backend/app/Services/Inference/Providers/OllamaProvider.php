<?php

namespace App\Services\Inference\Providers;

use App\Services\Inference\Contracts\InferenceProvider;
use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\ProviderHealth;
use App\Services\Inference\Data\TokenUsage;
use App\Services\Inference\Exceptions\ProviderException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Adapter for the local FastAPI services that wrap Ollama (workers/api.py) — the same
 * processes TedimLlmService (:8001) and BurmeseLlmService (:8002) already call. This
 * unifies them behind the InferenceProvider contract so the gateway treats local
 * Burmese/Tedim models exactly like Claude or OpenAI.
 *
 * One instance is configured per backend (name + base url + default model) by the
 * ModelRegistry, so a single class serves every Ollama-backed language.
 */
class OllamaProvider implements InferenceProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly string $defaultModel,
        private readonly int $timeout = 600,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function complete(InferenceRequest $request): InferenceResponse
    {
        $model = $request->model ?? $this->defaultModel;
        $startedAt = microtime(true);

        try {
            $resp = $this->http
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/v1/chat", [
                    'model'      => $model,
                    'messages'   => $request->messages,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                    'stream'     => false,
                ])
                ->throw();
        } catch (\Throwable $e) {
            throw new ProviderException($this->name, "Ollama call failed: {$e->getMessage()}", retryable: true, previous: $e);
        }

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        return new InferenceResponse(
            text: (string) $resp->json('text', ''),
            providerName: $this->name,
            model: $model,
            usage: new TokenUsage(
                (int) $resp->json('usage.prompt_tokens', 0),
                (int) $resp->json('usage.completion_tokens', 0),
            ),
            latencyMs: $latencyMs,
            finishReason: $resp->json('finish_reason'),
        );
    }

    public function stream(InferenceRequest $request): \Generator
    {
        $model = $request->model ?? $this->defaultModel;
        $startedAt = microtime(true);
        $buffer = '';

        $response = $this->http
            ->timeout($this->timeout)
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/v1/chat", [
                'model'      => $model,
                'messages'   => $request->messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'stream'     => true,
            ]);

        $body = $response->toPsrResponse()->getBody();
        while (! $body->eof()) {
            $line = trim($this->readLine($body));
            if ($line === '') {
                continue;
            }
            $payload = json_decode($line, true);
            $delta = $payload['delta'] ?? $payload['text'] ?? '';
            if ($delta !== '') {
                $buffer .= $delta;
                yield $delta;
            }
        }

        return new InferenceResponse(
            text: $buffer,
            providerName: $this->name,
            model: $model,
            usage: new TokenUsage(0, 0),
            latencyMs: (int) ((microtime(true) - $startedAt) * 1000),
        );
    }

    public function health(): ProviderHealth
    {
        $startedAt = microtime(true);
        try {
            $this->http->timeout(5)->get("{$this->baseUrl}/health")->throw();

            return ProviderHealth::up($this->name, (int) ((microtime(true) - $startedAt) * 1000));
        } catch (\Throwable $e) {
            return ProviderHealth::down($this->name, $e->getMessage());
        }
    }

    /** Read a single newline-delimited frame from a PSR stream. */
    private function readLine(\Psr\Http\Message\StreamInterface $body): string
    {
        $line = '';
        while (! $body->eof()) {
            $char = $body->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }

        return $line;
    }
}
