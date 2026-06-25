<?php

namespace App\Services\SessionState;

use App\Models\ChatSession;
use App\Models\SessionCheckpoint;
use App\Models\SessionNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single source of durable session state (see docs/session-state-store.md).
 *
 * A session is a graph of nodes with an explicit active pointer. The active LINEAR branch
 * is identified by the active node's `branch_id`; appends extend that branch with the next
 * `seq`. Forks open a new session that points back into the parent's node. Checkpoints are
 * rehydratable snapshots taken at a node — what `resume()` reconstructs.
 *
 * RULE: nodes are the only durable truth. Live streams and history mirrors are observers
 * that derive from nodes; they never define state.
 */
class SessionStateStore
{
    /** Load a session plus its active branch (and latest checkpoint). */
    public function get(string $sessionId): SessionState
    {
        $session = ChatSession::findOrFail($sessionId);

        $branch = $this->activeBranchNodes($session);
        $checkpoint = SessionCheckpoint::where('session_id', $sessionId)
            ->orderByDesc('created_at')->first();

        return new SessionState($session, $branch, $checkpoint);
    }

    /** `resume` is `get` — kept as a distinct verb so callers read intent at the call site. */
    public function resume(string $sessionId): SessionState
    {
        return $this->get($sessionId);
    }

    /**
     * Append a node to the session's active branch and advance the active pointer.
     * Returns the new node id. Transactional so branch/seq/pointer stay consistent.
     */
    public function appendNode(string $sessionId, SessionNodeData $data): string
    {
        return DB::transaction(function () use ($sessionId, $data) {
            $session = ChatSession::whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $active = $session->active_node_id
                ? SessionNode::find($session->active_node_id)
                : null;

            // Continue the active branch only when the active node belongs to THIS session.
            // A fresh fork's active pointer lives in the parent session → start a new branch.
            if ($active && $active->session_id === $sessionId) {
                $branchId = $active->branch_id;
                $seq = (int) SessionNode::where('branch_id', $branchId)->max('seq') + 1;
                $parentNodeId = $active->id;
            } else {
                $branchId = (string) Str::uuid();
                $seq = 1;
                $parentNodeId = $active?->id;          // cross-session fork point (or null)
            }

            $node = SessionNode::create([
                'id'             => (string) Str::uuid(),
                'session_id'     => $sessionId,
                'parent_node_id' => $parentNodeId,
                'branch_id'      => $branchId,
                'seq'            => $seq,
                'type'           => $data->type,
                'sender'         => $data->sender,
                'content'        => $data->content,
                'metadata'       => $data->metadata,
                'token_usage'    => $data->tokenUsage,
            ]);

            $session->forceFill(['active_node_id' => $node->id])->save();

            return $node->id;
        });
    }

    /**
     * Branch a session at a node: a new session sharing the lineage root, whose next append
     * continues from `fromNodeId`. No node copying — lineage is explicit. Returns the new id.
     */
    public function fork(string $sessionId, string $fromNodeId): string
    {
        $parent = ChatSession::findOrFail($sessionId);
        $fromNode = SessionNode::where('session_id', $sessionId)->whereKey($fromNodeId)->firstOrFail();

        $child = ChatSession::create([
            'user_id'           => $parent->user_id,
            'session_type'      => $parent->session_type,
            'language'          => $parent->language,
            'status'            => 'active',
            'root_session_id'   => $parent->root_session_id ?: $parent->id,
            'parent_session_id' => $parent->id,
            'parent_node_id'    => $fromNode->id,
            'active_node_id'    => $fromNode->id,   // next append branches from here
            'started_at'        => now(),
            'last_activity_at'  => now(),
        ]);

        return $child->id;
    }

    /** Snapshot rehydratable state at a node. Returns the checkpoint id. */
    public function checkpoint(string $sessionId, string $nodeId, array $state): string
    {
        $checkpoint = SessionCheckpoint::create([
            'session_id' => $sessionId,
            'node_id'    => $nodeId,
            'state_blob' => $state,
        ]);

        return (string) $checkpoint->id;
    }

    /**
     * Phase 3 read path: the active branch's message-type nodes mapped to the legacy
     * chat_messages DTO shape, so callers can serialize history straight from nodes (the
     * durable truth) without a chat_messages query. system_event/checkpoint nodes are
     * excluded — they are not chat turns. Order is by branch seq (== chronological).
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    public function messageDtos(ChatSession $session): Collection
    {
        // Read the active pointer from the DB — a caller's in-memory model may be stale
        // after a write advanced it within the same request.
        $session = $session->fresh() ?? $session;

        return $this->activeBranchNodes($session)
            ->where('type', 'message')
            ->values()
            ->map(fn (SessionNode $n) => new \Illuminate\Support\Fluent([
                'id'           => $n->id,
                'session_id'   => $n->session_id,
                'sender'       => $n->sender,
                'message_type' => $n->metadata['message_type'] ?? 'text',
                'content'      => $n->content,
                'metadata'     => $n->metadata,
                'token_usage'  => $n->token_usage,
                'created_at'   => $n->created_at,
            ]));
    }

    /**
     * Lightweight ['sender','content'] turns from the active branch's message nodes, in
     * chronological (seq) order, optionally limited to the first N. Used by the worker
     * dispatchers (pastor reply / title / journal) that need conversation context — the
     * Phase 4 replacement for reading the dropped chat_messages table.
     *
     * @return array<int,array{sender:string,content:string}>
     */
    public function messageTurns(ChatSession $session, int $limit = 0): array
    {
        $dtos = $this->messageDtos($session);
        if ($limit > 0) {
            $dtos = $dtos->take($limit);
        }

        return $dtos->map(fn ($m) => ['sender' => $m['sender'], 'content' => $m['content']])->values()->all();
    }

    /** Count of message-type nodes on the active branch (Phase 4 title-trigger gate). */
    public function messageCount(ChatSession $session): int
    {
        return $this->messageDtos($session)->count();
    }

    /** Nodes of the active branch, in order. Empty collection when nothing recorded yet. */
    private function activeBranchNodes(ChatSession $session)
    {
        $active = $session->active_node_id ? SessionNode::find($session->active_node_id) : null;
        if (! $active) {
            return collect();
        }

        return SessionNode::where('session_id', $session->id)
            ->where('branch_id', $active->branch_id)
            ->orderBy('seq')
            ->get();
    }
}
