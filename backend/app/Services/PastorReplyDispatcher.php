<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Redis;

/**
 * Composes an AI Pastor reply job server-side and pushes it to the worker queue
 * ('ai:history'). Only conversation text + (opt-in) prior-session summaries travel —
 * never secrets. Shared by the start pipeline and the follow-up message endpoint.
 */
class PastorReplyDispatcher
{
    public const QUEUE = 'ai:history';

    public function dispatch(ChatSession $session): void
    {
        $turns = ChatMessage::where('session_id', $session->id)
            ->orderBy('created_at')->limit(20)
            ->get(['sender', 'content'])
            ->map(fn ($m) => ['role' => $m->sender, 'content' => $m->content])->all();

        $job = [
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'mode'       => 'pastor_reply',
            'session_id' => $session->id,
            'language'   => $session->language,
            'turns'      => $turns,
            'memory'     => $this->memoryContext($session),
        ];

        Redis::rpush(self::QUEUE, json_encode($job));
    }

    /**
     * Prior-session summaries the pastor may reference ("Last week we studied
     * Romans 8…") — ONLY when the user has opted in (users.ai_memory_enabled).
     */
    private function memoryContext(ChatSession $session): array
    {
        $user = $session->user;
        if (! $user || ! ($user->ai_memory_enabled ?? true)) {
            return [];
        }

        return ChatSession::forUser($user->id)
            ->whereKeyNot($session->id)
            ->whereNotNull('summary')
            ->orderByDesc('last_activity_at')
            ->limit(3)
            ->get(['session_type', 'title', 'summary'])
            ->map(fn ($s) => [
                'type' => $s->session_type, 'title' => $s->title, 'summary' => $s->summary,
            ])->all();
    }
}
