<?php

namespace Tests\Feature;

use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\TokenUsage;
use App\Services\Inference\InferenceGateway;
use App\Services\Observability\Contracts\TraceStore;
use App\Services\Observability\Contracts\Tracer;
use App\Services\Observability\SpanTracer;
use App\Services\Observability\Store\ArrayTraceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end observability: a study turn emits one chat.request trace with the expected stage
 * spans, and the debug endpoint materialises it from the store WITHOUT re-running retrieval.
 */
class ChatTracingTest extends TestCase
{
    use RefreshDatabase;

    private ArrayTraceStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('observability.tracing.enabled', true);
        config()->set('observability.tracing.sample_rate', 1.0);
        config()->set('observability.debug_endpoint', true);

        // Deterministic, in-process trace store + tracer shared by orchestrator and debug endpoint.
        $this->store = new ArrayTraceStore();
        $this->instance(TraceStore::class, $this->store);
        $this->instance(Tracer::class, new SpanTracer($this->store));

        $this->instance(InferenceGateway::class, new class extends InferenceGateway {
            public function __construct() {}
            public function complete(InferenceRequest $r): InferenceResponse
            {
                return new InferenceResponse('Grace abounds.', 'claude', 'claude-sonnet-4-6', new TokenUsage(10, 6), 25);
            }
        });
    }

    public function test_study_turn_emits_trace_with_stage_spans(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/chat/study', ['message' => 'Explain grace'])
            ->assertOk()
            ->json();

        $trace = $this->store->get($res['correlation_id']);
        $this->assertNotNull($trace, 'a trace was recorded for the request');
        $this->assertSame('chat.request', $trace['name']);
        $this->assertSame('bible_study', $trace['attributes']['route']);

        $spanNames = array_map(fn ($c) => $c['name'], $trace['children']);
        $this->assertContains('guardrails.pre', $spanNames);
        $this->assertContains('inference.llm', $spanNames);
        $this->assertContains('guardrails.post', $spanNames);
        $this->assertContains('persistence.write', $spanNames);
    }

    public function test_debug_endpoint_materialises_trace_for_staff(): void
    {
        $user = $this->makeUser();
        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/chat/study', ['message' => 'Explain grace'])
            ->json();

        $staff = $this->makeUser(['role' => 'admin']);
        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/chat/debug/{$res['correlation_id']}")
            ->assertOk()
            ->assertJsonPath('correlation_id', $res['correlation_id'])
            ->assertJsonPath('trace.name', 'chat.request');
    }

    public function test_debug_endpoint_is_staff_only(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chat/debug/whatever')
            ->assertStatus(403);
    }

    public function test_debug_endpoint_404_for_unknown_trace(): void
    {
        $staff = $this->makeUser(['role' => 'admin']);
        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/chat/debug/does-not-exist')
            ->assertStatus(404);
    }
}
