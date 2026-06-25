<?php

namespace Tests\Feature;

use App\Models\BibleSessionMeta;
use App\Models\ChatSession;
use App\Models\SessionCheckpoint;
use App\Models\SessionNode;
use App\Models\ServiceSession;
use App\Models\ServiceSessionMeta;
use App\Models\StudySession;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SessionStateStore Phase 2: Study and Service worker webhooks write system_event nodes
 * + checkpoints onto their bridged unified-history sessions. Additive/non-breaking —
 * mirrors are best-effort, so the webhook still succeeds even without a bridge.
 */
class Phase2ModuleNodesTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $url, array $payload): \Illuminate\Testing\TestResponse
    {
        config(['services.worker.secret' => str_repeat('k', 48)]);
        $body = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, config('services.worker.secret'));

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_X_WORKER_TIMESTAMP' => $ts,
            'HTTP_X_WORKER_SIGNATURE' => $sig,
        ], $body);
    }

    /** asset-ready uses a plain shared-secret header, not the HMAC signature scheme. */
    private function postWithSecret(string $url, array $payload): \Illuminate\Testing\TestResponse
    {
        config(['services.worker.secret' => str_repeat('k', 48)]);

        return $this->withHeaders(['X-Worker-Secret' => config('services.worker.secret')])
            ->postJson($url, $payload);
    }

    public function test_study_turn_writes_event_node_and_checkpoint_on_bridged_session(): void
    {
        $user = $this->makeUser();
        $chat = app(HistoryService::class)->startSession($user, 'bible_study');
        $study = StudySession::create([
            'user_id' => $user->id, 'language' => 'en', 'translation' => 'BSB', 'state' => 'discussing', 'agent_count' => 3, 'stream_token' => hash('sha256', uniqid('', true)),
        ]);
        BibleSessionMeta::create(['chat_session_id' => $chat->id, 'study_session_id' => $study->id]);

        $this->sign('/api/internal/study-turn', [
            'session_id' => $study->id, 'turn' => 1, 'role' => 'pastor',
            'content' => 'Consider how the Shepherd leads.',
        ])->assertOk();

        $node = SessionNode::where('session_id', $chat->id)->where('type', 'system_event')->first();
        $this->assertNotNull($node);
        $this->assertSame('study_turn', $node->content);
        $this->assertSame('pastor', $node->metadata['role']);
        $this->assertSame(1, SessionCheckpoint::where('session_id', $chat->id)->count());
    }

    public function test_study_user_turn_does_not_write_node(): void
    {
        $user = $this->makeUser();
        $chat = app(HistoryService::class)->startSession($user, 'bible_study');
        $study = StudySession::create([
            'user_id' => $user->id, 'language' => 'en', 'translation' => 'BSB', 'state' => 'discussing', 'agent_count' => 3, 'stream_token' => hash('sha256', uniqid('', true)),
        ]);
        BibleSessionMeta::create(['chat_session_id' => $chat->id, 'study_session_id' => $study->id]);

        // role=user is not mirrored as a discussion node.
        $this->sign('/api/internal/study-turn', [
            'session_id' => $study->id, 'turn' => 1, 'role' => 'user', 'content' => 'My question',
        ])->assertOk();

        $this->assertSame(0, SessionNode::where('session_id', $chat->id)->count());
    }

    public function test_service_asset_ready_writes_milestone_node_and_checkpoint(): void
    {
        $user = $this->makeUser();
        $chat = app(HistoryService::class)->startSession($user, 'service');
        $svc = ServiceSession::create([
            'user_id' => $user->id, 'session_token' => 'tok_'.uniqid(), 'status' => 'active', 'language' => 'en',
        ]);
        ServiceSessionMeta::create(['chat_session_id' => $chat->id, 'service_session_id' => $svc->id]);

        $this->postWithSecret('/api/internal/asset-ready', [
            'session_token' => $svc->session_token, 'segment' => 'sermon',
            'asset_type' => 'audio', 'storage_key' => 'k/sermon.mp3',
        ])->assertOk();

        $node = SessionNode::where('session_id', $chat->id)->where('type', 'system_event')->first();
        $this->assertNotNull($node);
        $this->assertSame('service_segment_ready', $node->content);
        $this->assertSame('sermon', $node->metadata['segment']);

        $cp = SessionCheckpoint::where('session_id', $chat->id)->latest('id')->first();
        $this->assertContains('sermon', $cp->state_blob['ready_segments']);
    }

    public function test_service_asset_ready_succeeds_without_a_bridge(): void
    {
        $user = $this->makeUser();
        $svc = ServiceSession::create([
            'user_id' => $user->id, 'session_token' => 'tok_'.uniqid(), 'status' => 'active', 'language' => 'en',
        ]);

        // No ServiceSessionMeta bridge → best-effort mirror is skipped, webhook still 200s.
        $this->postWithSecret('/api/internal/asset-ready', [
            'session_token' => $svc->session_token, 'segment' => 'scripture',
            'asset_type' => 'text', 'text_payload' => 'Psalm 23:1',
        ])->assertOk();

        $this->assertSame(0, SessionNode::count());
    }
}
