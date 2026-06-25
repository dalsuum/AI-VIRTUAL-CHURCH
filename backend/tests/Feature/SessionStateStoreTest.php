<?php

namespace Tests\Feature;

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

    public function test_history_service_records_message_as_node_only(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'pastor');

        $nodeId = app(HistoryService::class)->recordMessage($session, 'user', 'Pray for my family');

        // Phase 4: session_nodes is the sole record (no chat_messages projection).
        $node = SessionNode::where('session_id', $session->id)->first();
        $this->assertNotNull($node);
        $this->assertSame($nodeId, $node->id);
        $this->assertSame('user', $node->sender);
        $this->assertSame('Pray for my family', $node->content);
        $this->assertSame($node->id, $session->fresh()->active_node_id);
    }

    public function test_record_event_writes_system_event_node_without_legacy_message(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'music');

        $nodeId = app(HistoryService::class)->recordEvent($session, 'playlist_recommended', [
            'mood' => 'hopeful', 'track_ids' => [1, 2, 3],
        ]);

        // Events are graph-only and never appear as message-type nodes.
        $this->assertSame(0, $this->store()->messageCount($session));
        $node = SessionNode::find($nodeId);
        $this->assertSame('system_event', $node->type);
        $this->assertSame('playlist_recommended', $node->content);
        $this->assertSame([1, 2, 3], $node->metadata['track_ids']);
        // The event advances the active pointer like any append.
        $this->assertSame($nodeId, $session->fresh()->active_node_id);
    }

    public function test_checkpoint_helper_snapshots_at_active_node(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'music');
        $history = app(HistoryService::class);

        $eventNode = $history->recordEvent($session, 'playlist_recommended', ['track_ids' => [7]]);
        $history->checkpoint($session, ['queue' => [7], 'track_id' => 7, 'position' => 0]);

        $state = $this->store()->resume($session->id);
        $this->assertNotNull($state->latestCheckpoint);
        $this->assertSame($eventNode, $state->latestCheckpoint->node_id, 'checkpoint binds to the active node');
        $this->assertSame([7], $state->latestCheckpoint->state_blob['queue']);
    }

    public function test_message_dtos_are_ordered_and_exclude_events(): void
    {
        $user = $this->makeUser();
        $session = app(HistoryService::class)->startSession($user, 'pastor');
        $history = app(HistoryService::class);

        $history->recordMessage($session, 'user', 'Teach me to pray');
        $history->recordMessage($session, 'assistant', 'Begin with thanksgiving');
        // A system_event node must NOT appear in the message-derived read.
        $history->recordEvent($session, 'playlist_recommended', ['x' => 1]);

        $dtos = $this->store()->messageDtos($session);

        $this->assertCount(2, $dtos, 'system_event excluded; only the two messages');
        $this->assertSame(
            ['Teach me to pray', 'Begin with thanksgiving'],
            $dtos->pluck('content')->all(),
            'node-derived read is in chronological (seq) order'
        );
        $this->assertSame(['user', 'assistant'], $dtos->pluck('sender')->all());
    }
}
