<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spiritual Journal API (/api/journal*, plus generation off a history session).
 * Entries are owner-scoped; generation is async — a 'pending' row is created and the
 * worker fills it via the HMAC history-callback, so the request returns immediately.
 */
class JournalController extends Controller
{
    private const QUEUE = 'ai:history';

    private function findEntry(Request $request, int $id): JournalEntry
    {
        return JournalEntry::forUser((int) $request->user()->id)
            ->whereKey($id)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
    }

    /** Generate a journal entry from an owned history session. */
    public function generate(Request $request, string $sessionId): JsonResponse
    {
        $session = ChatSession::forUser((int) $request->user()->id)
            ->whereKey($sessionId)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));

        $entry = JournalEntry::create([
            'user_id'         => $request->user()->id,
            'chat_session_id' => $session->id,
            'status'          => 'pending',
            'title'           => $session->title,
        ]);

        // Compose the job server-side: only conversation text + the session summary.
        $turns = ChatMessage::where('session_id', $session->id)
            ->orderBy('created_at')->limit(30)
            ->get(['sender', 'content'])
            ->map(fn ($m) => ['sender' => $m->sender, 'content' => $m->content])->all();

        Redis::rpush(self::QUEUE, json_encode([
            'mode'             => 'journal',
            'journal_entry_id' => $entry->id,
            'language'         => $session->language,
            'type'             => $session->session_type,
            'title'            => $session->title,
            'summary'          => $session->summary,
            'turns'            => $turns,
        ]));

        return response()->json(['entry' => $entry], 202);
    }

    /** List the caller's journal entries (most recent first). */
    public function index(Request $request): JsonResponse
    {
        $entries = JournalEntry::forUser((int) $request->user()->id)
            ->orderByDesc('created_at')
            ->cursorPaginate(20);

        return response()->json([
            'entries'     => $entries->items(),
            'next_cursor' => optional($entries->nextCursor())->encode(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['entry' => $this->findEntry($request, $id)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findEntry($request, $id)->delete();           // soft delete

        return response()->json(['ok' => true]);
    }
}
