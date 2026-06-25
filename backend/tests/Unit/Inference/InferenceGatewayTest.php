<?php

namespace Tests\Unit\Inference;

use App\Services\Inference\Contracts\InferenceProvider;
use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\ProviderHealth;
use App\Services\Inference\Data\TokenUsage;
use App\Services\Inference\Exceptions\NoProviderAvailableException;
use App\Services\Inference\Exceptions\ProviderException;
use App\Services\Inference\InferenceGateway;
use App\Services\Inference\InferenceMetrics;
use App\Services\Inference\ModelRegistry;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the gateway's CROSS-provider fallback in isolation. The registry and metrics
 * are mocked; fake providers stand in for Ollama/Claude so we test routing/fallback
 * policy without any network or container.
 */
class InferenceGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Route 'td' through a two-link chain so fallback has somewhere to go.
        config()->set('inference.routing', ['td' => ['ollama_tedim', 'claude']]);
        config()->set('inference.default_chain', ['claude']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeProvider(string $name, ?InferenceResponse $ok, ?\Throwable $throw = null): InferenceProvider
    {
        return new class($name, $ok, $throw) implements InferenceProvider {
            public function __construct(private string $n, private ?InferenceResponse $ok, private ?\Throwable $throw) {}
            public function name(): string { return $this->n; }
            public function complete(InferenceRequest $r): InferenceResponse
            {
                if ($this->throw) { throw $this->throw; }
                return $this->ok;
            }
            public function stream(InferenceRequest $r): \Generator { return $this->ok; yield; }
            public function health(): ProviderHealth { return ProviderHealth::up($this->n, 1); }
        };
    }

    private function response(string $provider): InferenceResponse
    {
        return new InferenceResponse('hello', $provider, 'm', new TokenUsage(1, 1), 10);
    }

    public function test_returns_first_healthy_provider(): void
    {
        $registry = Mockery::mock(ModelRegistry::class);
        $registry->shouldReceive('get')->with('ollama_tedim')
            ->andReturn($this->fakeProvider('ollama_tedim', $this->response('ollama_tedim')));

        $metrics = Mockery::mock(InferenceMetrics::class);
        $metrics->shouldReceive('success')->once();

        $gateway = new InferenceGateway($registry, $metrics);
        $res = $gateway->complete(new InferenceRequest([['role' => 'user', 'content' => 'hi']], language: 'td'));

        $this->assertSame('ollama_tedim', $res->providerName);
    }

    public function test_falls_back_when_first_provider_fails(): void
    {
        $registry = Mockery::mock(ModelRegistry::class);
        $registry->shouldReceive('get')->with('ollama_tedim')
            ->andReturn($this->fakeProvider('ollama_tedim', null, new ProviderException('ollama_tedim', 'down')));
        $registry->shouldReceive('get')->with('claude')
            ->andReturn($this->fakeProvider('claude', $this->response('claude')));

        $metrics = Mockery::mock(InferenceMetrics::class);
        $metrics->shouldReceive('failure')->once();
        $metrics->shouldReceive('fallback')->once()->with('ollama_tedim', 'claude', Mockery::any());
        $metrics->shouldReceive('success')->once();

        $gateway = new InferenceGateway($registry, $metrics);
        $res = $gateway->complete(new InferenceRequest([['role' => 'user', 'content' => 'hi']], language: 'td'));

        $this->assertSame('claude', $res->providerName, 'fell back to the second provider');
    }

    public function test_throws_when_whole_chain_exhausted(): void
    {
        $registry = Mockery::mock(ModelRegistry::class);
        $registry->shouldReceive('get')->with('ollama_tedim')
            ->andReturn($this->fakeProvider('ollama_tedim', null, new ProviderException('ollama_tedim', 'down')));
        $registry->shouldReceive('get')->with('claude')
            ->andReturn($this->fakeProvider('claude', null, new ProviderException('claude', 'down')));

        $metrics = Mockery::mock(InferenceMetrics::class);
        $metrics->shouldReceive('failure')->twice();
        $metrics->shouldReceive('fallback')->once();

        $gateway = new InferenceGateway($registry, $metrics);

        $this->expectException(NoProviderAvailableException::class);
        $gateway->complete(new InferenceRequest([['role' => 'user', 'content' => 'hi']], language: 'td'));
    }
}
