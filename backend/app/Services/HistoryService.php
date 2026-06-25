<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\SessionState\SessionNodeData;
use App\Services\SessionState\SessionStateStore;
use Illuminate\Support\Facades\Cache;

/**
 * The single write API every module calls to record into the unified history spine.
 * Modules never touch chat_sessions directly — they go through here so titling,
 * touch semantics and cache invalidation stay consistent.
 *
 * SessionStateStore (docs/session-state-store.md) is now fully cut over (Phase 4):
 * session_nodes is the SOLE durable record of conversation turns — the legacy
 * chat_messages projection has been dropped. Reads and writes both go through
 * SessionStateStore; this service stays the single write entrypoint for titling/touch/
 * cache-invalidation consistency.
 */
class HistoryService
{
    public function __construct(
        private readonly HistoryTitleService $titles,
        private readonly SessionStateStore $state,
    ) {}

    /** Start (or return an existing) session of a given type for a user. */
    public function startSession(User $user, string $type, array $attrs = []): ChatSession
    {
        $session = ChatSession::create(array_merge([
            'user_id'          => $user->id,
            'session_type'     => $type,
            'status'           => 'active',
            'started_at'       => now(),
            'last_activity_at' => now(),
        ], $attrs));

        $this->forgetListCache($user->id);

        return $session;
    }

    /**
     * Append a message turn and bump activity. Triggers auto-title once enough turns exist.
     * Phase 4: session_nodes is the sole record (the legacy chat_messages dual-write is
     * gone). Returns the new node id.
     */
    public function recordMessage(ChatSession $session, string $sender, string $content, array $opts = []): string
    {
        $metadata = $opts['metadata'] ?? null;
        if (isset($opts['message_type']) && $opts['message_type'] !== 'text') {
            $metadata = array_merge($metadata ?? [], ['message_type' => $opts['message_type']]);
        }

        $nodeId = $this->state->appendNode($session->id, SessionNodeData::message(
            $sender, $content, $metadata, $opts['token_usage'] ?? null
        ));

        $this->touch($session);

        // Auto-title after enough back-and-forth, ChatGPT-style — only once.
        if ($session->fresh()?->title === null && $this->state->messageCount($session) >= 3) {
            $this->titles->enqueue($session);
        }

        return $nodeId;
    }

    /**
     * Record a non-message graph event (Phase 2): a service milestone, music playback
     * event or study round marker. Unlike recordMessage this writes ONLY a session_node
     * (system_event) — there is no legacy chat_messages projection for non-chat events.
     * Best-effort by design: node failures must never abort the charged module flow, so
     * callers wrap this in try/catch exactly like the existing history mirrors.
     */
    public function recordEvent(ChatSession $session, string $event, array $metadata = []): string
    {
        $nodeId = $this->state->appendNode(
            $session->id,
            SessionNodeData::systemEvent($event, $metadata ?: null)
        );
        $this->touch($session);

        return $nodeId;
    }

    /**
     * Snapshot rehydratable module state at the session's active node (Phase 2):
     * study round/engine state, music playback position, service-state milestones.
     * Returns the checkpoint id.
     */
    public function checkpoint(ChatSession $session, array $state): string
    {
        // Read the active pointer from the DB — the caller's in-memory model may be stale
        // after a preceding recordEvent/recordMessage advanced it.
        $nodeId = $session->fresh()?->active_node_id
            ?? $this->state->appendNode($session->id, SessionNodeData::systemEvent('checkpoint'));

        return $this->state->checkpoint($session->id, $nodeId, $state);
    }

    /** Update last activity (and invalidate the cached sidebar page). */
    public function touch(ChatSession $session): void
    {
        $session->forceFill(['last_activity_at' => now()])->save();
        $this->forgetListCache($session->user_id);
    }

    /** Mark a session ended/completed. */
    public function complete(ChatSession $session): void
    {
        $session->update(['status' => 'completed', 'ended_at' => now()]);
        $this->forgetListCache($session->user_id);
    }

    /** Redis-cached first sidebar page is keyed per user; drop it on any write. */
    public function forgetListCache(int $userId): void
    {
        Cache::forget("history:list:{$userId}");
    }
}
