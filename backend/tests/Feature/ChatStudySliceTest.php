<?php

namespace Tests\Feature;

use App\Services\Chat\Data\KnowledgeContext;
use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\TokenUsage;
use App\Services\Inference\InferenceGateway;
use App\Services\SessionState\SessionStateStore;
use App\Models\ChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end test of the first AI-platform slice: HTTP → ChatOrchestrator → guards →
 * knowledge → inference → output guards → persistence → JSON. The InferenceGateway is faked
 * (no real LLM); everything else runs for real, proving the layers compose through the live
 * container and routes.
 */
class ChatStudySliceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGateway(string $text): InferenceGateway
    {
        return new class($text) extends InferenceGateway {
            public function __construct(private string $text) {}
            public function complete(InferenceRequest $r): InferenceResponse
            {
                return new InferenceResponse($this->text, 'claude', 'claude-sonnet-4-6', new TokenUsage(12, 8), 33);
            }
        };
    }

    public function test_study_slice_returns_reply_and_persists_turns(): void
    {
        $this->instance(InferenceGateway::class, $this->fakeGateway('John 3:16 speaks of God\'s love.'));
        $user = $this->makeUser();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/chat/study', ['message' => 'What does John 3:16 mean?'])
            ->assertOk()
            ->json();

        $this->assertFalse($res['blocked']);
        $this->assertSame('bible_study', $res['capability']);
        $this->assertNotEmpty($res['correlation_id']);
        $this->assertStringContainsString('John 3:16', $res['message']);

        // The full turn was persisted into the unified history spine (user + assistant).
        $session = ChatSession::findOrFail($res['session_id']);
        $this->assertSame($user->id, $session->user_id);
        $turns = app(SessionStateStore::class)->messageTurns($session, 10);
        $this->assertCount(2, $turns);
        $this->assertSame('user', $turns[0]['sender']);
        $this->assertSame('assistant', $turns[1]['sender']);
    }

    public function test_username_is_stripped_from_reply(): void
    {
        $user = $this->makeUser(['name' => 'Mary']);
        $this->instance(InferenceGateway::class, $this->fakeGateway('Mary, peace be with you.'));

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/chat/study', ['message' => 'Encourage me'])
            ->assertOk()
            ->json();

        $this->assertStringNotContainsString('Mary', $res['message'], 'no-username output policy enforced end-to-end');
    }

    public function test_orchestrator_survives_total_knowledge_outage(): void
    {
        // Anti-cascade: even a KnowledgeRetriever that throws must not become a chat failure.
        $this->instance(\App\Services\Chat\Contracts\KnowledgeRetriever::class, new class implements \App\Services\Chat\Contracts\KnowledgeRetriever {
            public function retrieve(string $query, array $filters = []): \App\Services\Chat\Data\KnowledgeContext
            {
                throw new \RuntimeException('everything is down');
            }
        });
        $this->instance(InferenceGateway::class, $this->fakeGateway('Grace and peace.'));
        $user = $this->makeUser();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/chat/study', ['message' => 'Teach me about grace'])
            ->assertOk()
            ->json();

        $this->assertFalse($res['blocked'], 'system degrades, never cascades into a chat failure');
        $this->assertSame('Grace and peace.', $res['message']);
    }

    public function test_validation_rejects_empty_message(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/chat/study', ['message' => ''])
            ->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/chat/study', ['message' => 'hi'])->assertStatus(401);
    }

    public function test_pastor_chat_knowledge_use_follows_config_flag(): void
    {
        $pastor = new \App\Services\Chat\Capabilities\PastorChatCapability();

        config(['knowledge.capabilities.pastor_uses_knowledge' => true]);
        $this->assertTrue($pastor->usesKnowledge(), 'Pastor Chat should retrieve when the flag is on.');

        config(['knowledge.capabilities.pastor_uses_knowledge' => false]);
        $this->assertFalse($pastor->usesKnowledge(), 'Pastor Chat should stay relational when the flag is off.');
    }
}
