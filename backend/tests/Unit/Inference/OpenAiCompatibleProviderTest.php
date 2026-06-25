<?php

namespace Tests\Unit\Inference;

use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Exceptions\ProviderException;
use App\Services\Inference\Providers\OpenAiCompatibleProvider;
use Illuminate\Http\Client\Factory as Http;
use PHPUnit\Framework\TestCase;

/**
 * OpenAI-compatible adapter (OpenRouter/OpenAI/DeepSeek/LM Studio) with a faked HTTP client —
 * verifies chat-completions parsing, bearer auth + endpoint, and retryable error classification.
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    private function provider(Http $http): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider($http, 'openrouter', 'https://openrouter.ai/api/v1', 'sk-test', 'anthropic/claude-sonnet-4-6');
    }

    public function test_completion_parses_choices_and_usage(): void
    {
        $http = new Http();
        $http->fake(['*/chat/completions' => $http->response([
            'choices' => [['message' => ['content' => 'Grace abounds.'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 12, 'completion_tokens' => 5],
        ], 200)]);

        $res = $this->provider($http)->complete(
            new InferenceRequest([['role' => 'user', 'content' => 'hi']], maxTokens: 64),
        );

        $this->assertSame('Grace abounds.', $res->text);
        $this->assertSame('openrouter', $res->providerName);
        $this->assertSame(12, $res->usage->promptTokens);
        $this->assertSame(5, $res->usage->completionTokens);

        $http->assertSent(fn ($req) => str_contains($req->url(), '/chat/completions')
            && $req->hasHeader('Authorization', 'Bearer sk-test')
            && $req['model'] === 'anthropic/claude-sonnet-4-6');
    }

    public function test_server_error_is_retryable_provider_exception(): void
    {
        $http = new Http();
        $http->fake(['*' => $http->response('upstream boom', 503)]);

        try {
            $this->provider($http)->complete(new InferenceRequest([['role' => 'user', 'content' => 'hi']]));
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('openrouter', $e->provider);
            $this->assertTrue($e->retryable, '5xx should be retryable');
        }
    }
}
