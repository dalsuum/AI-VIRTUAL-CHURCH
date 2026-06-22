<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\SessionNode;
use App\Services\HistoryService;
use App\Services\SessionState\SessionNodeData;
use App\Services\SessionState\SessionStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 of the session-state engine: append/seq/active-pointer, fork lineage,
 * checkpoint/resume, and the dual-write guarantee (chat_messages + session_nodes stay
 * 1:1 and consistent through HistoryService).
 */
class SessionStateStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(): SessionStateStore
    {
        return app(SessionStateStore::class);
    }

    public function test_append_assigns_monotonic_seq_and_advances_active_pointer(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'pastor');
        $store = $this->store();

        $n1 = $store->appendNode($session->id, SessionNodeData::message('user', 'Hello'));
        $n2 = $store->appendNode($session->id, SessionNodeData::message('assistant', 'Peace be with you'));
        $n3 = $store->appendNode($session->id, SessionNodeData::message('user', 'Thank you'));

        $nodes = SessionNode::where('session_id', $session->id)->orderBy('seq')->get();
        $this->assertSame([1, 2, 3], $nodes->pluck('seq')->all());
        // One linear branch, chained by parent_node_id.
        $this->assertSame(1, $nodes->pluck('branch_id')->unique()->count());
        $this->assertNull($nodes[0]->parent_node_id);
        $this->assertSame($n1, $nodes[1]->parent_node_id);
        $this->assertSame($n2, $nodes[2]->parent_node_id);
        // Active pointer is the last node.
        $this->assertSame($n3, $session->fresh()->active_node_id);
    }

    public function test_fork_branches_lineage_without_copying_nodes(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'pastor');
        $store = $this->store();

        $store->appendNode($session->id, SessionNodeData::message('user', 'A'));
        $forkFrom = $store->appendNode($session->id, SessionNodeData::message('assistant', 'B'));
        $store->appendNode($session->id, SessionNodeData::message('user', 'C'));

        $childId = $store->fork($session->id, $forkFrom);
        $child = \App\Models\ChatSession::find($childId);

        // Lineage is explicit; no nodes were duplicated into the child yet.
        $this->assertSame($session->id, $child->parent_session_id);
        $this->assertSame($session->id, $child->root_session_id);
        $this->assertSame($forkFrom, $child->parent_node_id);
        $this->assertSame(0, SessionNode::where('session_id', $childId)->count());

        // The child's first append starts a NEW branch rooted at the fork point.
        $d = $store->appendNode($childId, SessionNodeData::message('user', 'D'));
        $dNode = SessionNode::find($d);
        $this->assertSame($forkFrom, $dNode->parent_node_id);
        $this->assertSame(1, $dNode->seq);
        $this->assertNotSame(
            SessionNode::find($forkFrom)->branch_id,
            $dNode->branch_id,
            'fork must open a new branch, not extend the parent branch'
        );
        // Original session is untouched (still 3 nodes).
        $this->assertSame(3, SessionNode::where('session_id', $session->id)->count());
    }

    public function test_checkpoint_and_resume_round_trip(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'music');
        $store = $this->store();
        $node = $store->appendNode($session->id, SessionNodeData::message('system', 'playing'));

        $store->checkpoint($session->id, $node, ['track_id' => 42, 'position' => 87, 'shuffle' => true]);

        $state = $store->resume($session->id);
        $this->assertNotNull($state->latestCheckpoint);
        $this->assertSame(42, $state->latestCheckpoint->state_blob['track_id']);
        $this->assertSame(87, $state->latestCheckpoint->state_blob['position']);
        $this->assertCount(1, $state->activeBranch);
    }

    public function test_history_service_dual_writes_message_and_node(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'pastor');

        app(HistoryService::class)->recordMessage($session, 'user', 'Pray for my family');

        // Legacy projection AND the graph node both exist and agree.
        $this->assertSame(1, ChatMessage::where('session_id', $session->id)->count());
        $node = SessionNode::where('session_id', $session->id)->first();
        $this->assertNotNull($node);
        $this->assertSame('user', $node->sender);
        $this->assertSame('Pray for my family', $node->content);
        $this->assertSame($node->id, $session->fresh()->active_node_id);
    }
}
