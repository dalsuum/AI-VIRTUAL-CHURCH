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
 * Adapter for Anthropic's Claude Messages API. The API key is injected by the
 * ModelRegistry (resolved from AiProviderProfile::resolveKey() or env) and never
 * logged, never placed in a job payload.
 *
 * The Messages API takes the system prompt as a TOP-LEVEL field, not a message role,
 * so this adapter splits any {role:system} entries out of the portable message list.
 *
 * Default model ids (Jan 2026): claude-opus-4-8, claude-sonnet-4-6,
 * claude-haiku-4-5-20251001. Chat defaults to Sonnet for cost/latency; callers may
 * override per request via InferenceRequest::model.
 */
class ClaudeProvider implements InferenceProvider
{
    private const API = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly string $defaultModel = 'claude-sonnet-4-6',
        private readonly int $timeout = 120,
    ) {}

    public function name(): string
    {
        return 'claude';
    }

    public function complete(InferenceRequest $request): InferenceResponse
    {
        $model = $request->model ?? $this->defaultModel;
        [$system, $messages] = $this->split($request->messages);
        $startedAt = microtime(true);

        try {
            $resp = $this->http
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::VERSION,
                ])
                ->timeout($this->timeout)
                ->post(self::API, array_filter([
                    'model'      => $model,
                    'system'     => $system,
                    'messages'   => $messages,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                ], fn ($v) => $v !== null))
                ->throw();
        } catch (\Throwable $e) {
            // 4xx (bad request / overloaded model) is not worth retrying on Claude;
            // transport/5xx is. We treat the HTTP client's RequestException status here.
            $status = method_exists($e, 'response') && $e->response() ? $e->response()->status() : 500;
            $retryable = $status >= 500 || $status === 429;
            throw new ProviderException('claude', "Claude call failed ({$status})", $retryable, $e);
        }

        return new InferenceResponse(
            text: (string) $resp->json('content.0.text', ''),
            providerName: 'claude',
            model: $model,
            usage: new TokenUsage(
                (int) $resp->json('usage.input_tokens', 0),
                (int) $resp->json('usage.output_tokens', 0),
            ),
            latencyMs: (int) ((microtime(true) - $startedAt) * 1000),
            finishReason: $resp->json('stop_reason'),
        );
    }

    public function stream(InferenceRequest $request): \Generator
    {
        $model = $request->model ?? $this->defaultModel;
        [$system, $messages] = $this->split($request->messages);
        $startedAt = microtime(true);
        $buffer = '';

        $response = $this->http
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::VERSION,
            ])
            ->timeout($this->timeout)
            ->withOptions(['stream' => true])
            ->post(self::API, array_filter([
                'model'      => $model,
                'system'     => $system,
                'messages'   => $messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'stream'     => true,
            ], fn ($v) => $v !== null));

        $body = $response->toPsrResponse()->getBody();
        foreach ($this->parseSse($body) as $event) {
            if (($event['type'] ?? null) === 'content_block_delta') {
                $delta = $event['delta']['text'] ?? '';
                if ($delta !== '') {
                    $buffer .= $delta;
                    yield $delta;
                }
            }
        }

        return new InferenceResponse(
            text: $buffer,
            providerName: 'claude',
            model: $model,
            usage: new TokenUsage(0, 0),
            latencyMs: (int) ((microtime(true) - $startedAt) * 1000),
        );
    }

    public function health(): ProviderHealth
    {
        // A 1-token ping is the cheapest reliable liveness signal for a hosted API.
        $startedAt = microtime(true);
        try {
            $this->http
                ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => self::VERSION])
                ->timeout(10)
                ->post(self::API, [
                    'model'      => $this->defaultModel,
                    'max_tokens' => 1,
                    'messages'   => [['role' => 'user', 'content' => 'ping']],
                ])
                ->throw();

            return ProviderHealth::up('claude', (int) ((microtime(true) - $startedAt) * 1000));
        } catch (\Throwable $e) {
            return ProviderHealth::down('claude', $e->getMessage());
        }
    }

    /**
     * Split portable messages into Anthropic's (system string, messages[]) shape.
     *
     * @param list<array{role:string,content:string}> $messages
     * @return array{0:?string,1:list<array{role:string,content:string}>}
     */
    private function split(array $messages): array
    {
        $system = null;
        $rest = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system = $system === null ? $m['content'] : $system . "\n\n" . $m['content'];
            } else {
                $rest[] = $m;
            }
        }

        return [$system, $rest];
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
