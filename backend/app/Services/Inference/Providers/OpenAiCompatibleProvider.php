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
 * Adapter for any OpenAI-compatible chat-completions API: OpenRouter, OpenAI, DeepSeek,
 * LM Studio, vLLM. Configured per backend with a base URL + bearer key + model id, so one
 * class serves all of them. The API key (from AiProviderProfile or env) is sent as a Bearer
 * token and never logged.
 *
 * Portable {role,content} messages map 1:1 to the chat-completions schema, so no system-prompt
 * splitting is needed (unlike the native Anthropic adapter).
 */
class OpenAiCompatibleProvider implements InferenceProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $defaultModel,
        private readonly int $timeout = 120,
        /** @var array<string,string> optional extra headers (OpenRouter HTTP-Referer / X-Title) */
        private readonly array $headers = [],
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
            $resp = $this->client()
                ->timeout($this->timeout)
                ->post($this->endpoint(), [
                    'model'       => $model,
                    'messages'    => $request->messages,
                    'max_tokens'  => $request->maxTokens,
                    'temperature' => $request->temperature,
                ])
                ->throw();
        } catch (\Throwable $e) {
            $status = method_exists($e, 'response') && $e->response() ? $e->response()->status() : 500;
            $retryable = $status >= 500 || $status === 429;
            throw new ProviderException($this->name, "{$this->name} call failed ({$status})", $retryable, $e);
        }

        return new InferenceResponse(
            text: (string) $resp->json('choices.0.message.content', ''),
            providerName: $this->name,
            model: $model,
            usage: new TokenUsage(
                (int) $resp->json('usage.prompt_tokens', 0),
                (int) $resp->json('usage.completion_tokens', 0),
            ),
            latencyMs: (int) ((microtime(true) - $startedAt) * 1000),
            finishReason: $resp->json('choices.0.finish_reason'),
        );
    }

    public function stream(InferenceRequest $request): \Generator
    {
        $model = $request->model ?? $this->defaultModel;
        $startedAt = microtime(true);
        $buffer = '';

        $response = $this->client()
            ->timeout($this->timeout)
            ->withOptions(['stream' => true])
            ->post($this->endpoint(), [
                'model'       => $model,
                'messages'    => $request->messages,
                'max_tokens'  => $request->maxTokens,
                'temperature' => $request->temperature,
                'stream'      => true,
            ]);

        $body = $response->toPsrResponse()->getBody();
        foreach ($this->parseSse($body) as $event) {
            $delta = $event['choices'][0]['delta']['content'] ?? '';
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
            $this->client()->timeout(10)->post($this->endpoint(), [
                'model'      => $this->defaultModel,
                'max_tokens' => 1,
                'messages'   => [['role' => 'user', 'content' => 'ping']],
            ])->throw();

            return ProviderHealth::up($this->name, (int) ((microtime(true) - $startedAt) * 1000));
        } catch (\Throwable $e) {
            return ProviderHealth::down($this->name, $e->getMessage());
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http->withToken($this->apiKey)->withHeaders($this->headers);
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function parseSse(\Psr\Http\Message\StreamInterface $body): \Generator
    {
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (str_starts_with($line, 'data:')) {
                    $json = trim(substr($line, 5));
                    if ($json !== '' && $json !== '[DONE]') {
                        $decoded = json_decode($json, true);
                        if (is_array($decoded)) {
                            yield $decoded;
                        }
                    }
                }
            }
        }
    }
}
