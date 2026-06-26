<?php

namespace App\Services\Inference;

use App\Models\AiProviderProfile;
use App\Services\Inference\Contracts\InferenceProvider;
use App\Services\Inference\Providers\ClaudeProvider;
use App\Services\Inference\Providers\OllamaProvider;
use App\Services\Inference\Providers\OpenAiCompatibleProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;

/**
 * Resolves a logical provider name (e.g. "claude", "ollama_tedim") into a ready,
 * resilience-wrapped InferenceProvider. This is the "model registry": connection
 * details, default models and credentials live in AiProviderProfile (DB, keys
 * encrypted) with config/inference.php providing static routing and the local Ollama
 * endpoints that are not credentialed.
 *
 * The registry builds providers; it does NOT decide WHICH provider a request should use
 * (that routing — language/purpose → provider chain — is config the gateway reads). It
 * stays inference-infrastructure only, with no orchestration knowledge.
 */
class ModelRegistry
{
    /** @var array<string,InferenceProvider> in-process memoisation */
    private array $built = [];

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    /** All provider names known to the registry (config + enabled DB profiles). */
    public function names(): array
    {
        $static = array_keys(config('inference.providers', []));
        $dynamic = AiProviderProfile::where('enabled', true)->pluck('name')->all();

        return array_values(array_unique([...$static, ...$dynamic]));
    }

    public function get(string $name): InferenceProvider
    {
        return $this->built[$name] ??= $this->wrap($this->build($name));
    }

    private function build(string $name): InferenceProvider
    {
        $static = config("inference.providers.{$name}");
        if (is_array($static)) {
            return $this->fromConfig($name, $static);
        }

        $profile = AiProviderProfile::where('name', $name)->where('enabled', true)->first();
        if ($profile) {
            return $this->fromProfile($profile);
        }

        throw new InvalidArgumentException("Unknown inference provider [{$name}]");
    }

    /** @param array<string,mixed> $cfg */
    private function fromConfig(string $name, array $cfg): InferenceProvider
    {
        return match ($cfg['driver']) {
            'ollama' => new OllamaProvider(
                $this->http, $name, $cfg['base_url'], $cfg['model'], (int) ($cfg['timeout'] ?? 600),
            ),
            'claude' => new ClaudeProvider(
                $this->http,
                (string) ($cfg['key'] ?: throw new InvalidArgumentException('Claude API key missing')),
                $cfg['model'] ?? 'claude-sonnet-4-6',
                (int) ($cfg['timeout'] ?? 120),
            ),
            'openai', 'openrouter' => new OpenAiCompatibleProvider(
                $this->http, $name,
                (string) ($cfg['base_url'] ?? 'https://openrouter.ai/api/v1'),
                (string) ($cfg['key'] ?: throw new InvalidArgumentException("API key missing for [{$name}]")),
                (string) ($cfg['model'] ?? ''),
                (int) ($cfg['timeout'] ?? 120),
                (array) ($cfg['headers'] ?? []),
            ),
            default => throw new InvalidArgumentException("Unsupported driver [{$cfg['driver']}] for [{$name}]"),
        };
    }

    private function fromProfile(AiProviderProfile $profile): InferenceProvider
    {
        // OpenRouter + generic OpenAI-compatible backends (LM Studio/vLLM/DeepSeek) all speak
        // the OpenAI chat-completions schema → one adapter, configured from the profile.
        return match ($profile->type) {
            'openrouter', 'openai_compatible', 'lmstudio', 'runpod' => new OpenAiCompatibleProvider(
                $this->http, $profile->name,
                (string) $profile->base_url,
                (string) ($profile->resolveKey() ?: throw new InvalidArgumentException("API key missing for [{$profile->name}]")),
                (string) $profile->model,
            ),
            // Native Ollama HTTP wrapper exposed by the FastAPI worker.
            'ollama' => new OllamaProvider(
                $this->http, $profile->name, (string) $profile->base_url, (string) $profile->model,
            ),
            default => throw new InvalidArgumentException("Unsupported profile type [{$profile->type}]"),
        };
    }

    private function wrap(InferenceProvider $provider): InferenceProvider
    {
        return new ResilientProvider(
            $provider,
            new CircuitBreaker(
                $this->cache,
                (int) config('inference.circuit.failure_threshold', 5),
                (int) config('inference.circuit.cooldown_seconds', 30),
            ),
            (int) config('inference.retry.max', 2),
            (int) config('inference.retry.backoff_ms', 250),
        );
    }
}
