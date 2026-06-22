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
