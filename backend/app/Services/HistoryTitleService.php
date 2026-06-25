<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Services\SessionState\SessionStateStore;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Generates short titles + 2–5 sentence summaries + auto-tags for a session, the
 * ChatGPT way. The real work runs on the Python worker (queue 'ai:history'); this
 * just composes the job server-side and pushes it. A deterministic fallback title is
 * written immediately so the sidebar is never blank if the worker is down.
 *
 * SECURITY: only conversation text travels in the job; no secrets, no provider keys.
 */
class HistoryTitleService
{
    public const QUEUE = 'ai:history';

    /** Fixed spiritual tag vocabulary the worker may choose from (also used to seed). */
    public const TAG_VOCAB = [
        'Faith', 'Hope', 'Anxiety', 'Healing', 'Marriage', 'Children', 'Salvation',
        'Prayer', 'Holy Spirit', 'Forgiveness', 'Love', 'Grace',
    ];

    public function enqueue(ChatSession $session): void
    {
        // Write a deterministic placeholder first so the UI has something now.
        if ($session->title === null) {
            $session->forceFill(['title' => $this->fallbackTitle($session)])->save();
        }

        $turns = app(SessionStateStore::class)->messageTurns($session, 8);

        $job = [
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'mode'       => 'title_summary',
            'session_id' => $session->id,
            'language'   => $session->language,
            'type'       => $session->session_type,
            'turns'      => $turns,
            'tag_vocab'  => self::TAG_VOCAB,
        ];

        Redis::rpush(self::QUEUE, json_encode($job));
    }

    /** A readable, non-empty title derived from the first user turn or the type. */
    public function fallbackTitle(ChatSession $session): string
    {
        $first = collect(app(SessionStateStore::class)->messageTurns($session))
            ->firstWhere('sender', 'user')['content'] ?? null;

        if ($first) {
            return Str::limit(trim(preg_replace('/\s+/', ' ', $first)), 48, '…');
        }

        return match ($session->session_type) {
            'bible_study' => 'Bible Study',
            'prayer'      => 'Prayer Session',
            'music'       => 'Worship Session',
            'service'     => 'Church Service',
            'pastor'      => 'Pastor Chat',
            'devotion'    => 'Devotion',
            default       => 'Conversation',
        };
    }
}
