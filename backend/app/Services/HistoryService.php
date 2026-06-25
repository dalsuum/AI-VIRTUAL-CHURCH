<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\SessionState\SessionNodeData;
use App\Services\SessionState\SessionStateStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single write API every module calls to record into the unified history spine.
 * Modules never touch chat_sessions directly — they go through here so titling,
 * touch semantics and cache invalidation stay consistent.
 *
 * Phase 1 of SessionStateStore (docs/session-state-store.md): every message write is
 * DUAL-WRITTEN — to the legacy chat_messages projection (still the read path) AND to
 * session_nodes (the future durable truth) — atomically, so the two never diverge. The
 * read path is unchanged; nodes accumulate for the later read switch.
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

    /** Append a message and bump activity. Triggers auto-title once enough turns exist. */
    public function recordMessage(ChatSession $session, string $sender, string $content, array $opts = []): ChatMessage
    {
        // Dual-write: legacy projection + graph node, atomically (Phase 1).
        $message = DB::transaction(function () use ($session, $sender, $content, $opts) {
            $msg = ChatMessage::create([
                'session_id'   => $session->id,
                'sender'       => $sender,
                'message_type' => $opts['message_type'] ?? 'text',
                'content'      => $content,
                'metadata'     => $opts['metadata'] ?? null,
                'token_usage'  => $opts['token_usage'] ?? null,
            ]);

            $this->state->appendNode($session->id, SessionNodeData::message(
                $sender, $content, $opts['metadata'] ?? null, $opts['token_usage'] ?? null
            ));

            return $msg;
        });

        $this->touch($session);

        // Auto-title after enough back-and-forth, ChatGPT-style — only once.
        if ($session->title === null
            && ChatMessage::where('session_id', $session->id)->count() >= 3) {
            $this->titles->enqueue($session);
        }

        return $message;
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
