<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\GuestUsageService;
use App\Services\HistoryService;
use App\Services\TokenService;
use App\Services\UsageLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI Pastor Chat — a single-assistant streaming conversation that writes into the
 * unified history spine (session_type 'pastor'). The reply is generated on the
 * Python worker (queue 'ai:history') and streamed back over SSE via a Redis event
 * list. All access is owner-scoped; the live stream additionally checks a hash-only
 * stream token. User text is conversation DATA only.
 */
class PastorChatController extends Controller
{
    private const QUEUE = 'ai:history';

    public function __construct(
        private readonly HistoryService $history,
        private readonly TokenService $tokens,
        private readonly GuestUsageService $guests,
        private readonly UsageLogger $usage,
    ) {}

    private function eventsKey(ChatSession $s): string
    {
        return "pastor:{$s->id}:events";
    }

    private function findOwned(Request $request, string $id): ChatSession
    {
        return ChatSession::forUser((int) $request->user()->id)
            ->where('session_type', 'pastor')
            ->whereKey($id)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
    }

    /** Start a Pastor Chat: charge a token, post the first message, dispatch a reply. */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'message'  => ['required', 'string', 'max:4000'],
            'language' => ['nullable', 'string', 'max:12'],
        ]);

        $session = $this->history->startSession($user, 'pastor', [
            'language' => $data['language'] ?? ($user->fav_language ?? 'en'),
        ]);
        $plaintextToken = $session->issueStreamToken();
        $session->save();

        $reservation = $user->isGuestAccount()
            ? null
            : $this->tokens->reserve($user, 'pastor', "pastor:{$session->id}");

        try {
            $this->history->recordMessage($session, 'user', trim($data['message']));
            $this->dispatchReply($session);
        } catch (\Throwable $e) {
            $reservation && $this->tokens->rollback($reservation);
            $this->usage->record($user, 'pastor', 'failed', 0, "pastor:{$session->id}");
            throw $e;
        }

        $reservation ? $this->tokens->commit($reservation) : $this->guests->record($request, 'pastor');
        $this->usage->record($user, 'pastor', 'ok', $reservation?->amount ?? 0, "pastor:{$session->id}");

        return response()->json([
            'session'      => ['id' => $session->id, 'language' => $session->language],
            'stream_token' => $plaintextToken,         // returned ONCE
        ], 201);
    }

    /** Post a follow-up message and dispatch a new reply. */
    public function postMessage(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $this->history->recordMessage($session, 'user', trim($data['message']));
        $this->dispatchReply($session);

        return response()->json(['ok' => true]);
    }

    /** Owner-scoped message history for hydration/resume. */
    public function messages(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);

        return response()->json([
            'messages' => $session->messages()->get(['sender', 'content', 'created_at']),
        ]);
    }

    /** Live SSE — tails the Redis events list for this session. */
    public function stream(Request $request, string $id): StreamedResponse
    {
        $session = $this->findOwned($request, $id);
        $token = (string) $request->query('token', '');
        abort_unless($token !== '' && $session->verifyStreamToken($token), 403, 'Invalid stream token.');

        $key = $this->eventsKey($session);
        $after = max(0, (int) $request->query('after_seq', 0));
        $maxSecs = 120;
        $beat = 15;

        return new StreamedResponse(function () use ($key, $after, $maxSecs, $beat) {
            $start = time();
            $cursor = $after;
            $lastBeat = time();
            while (true) {
                if (connection_aborted() || (time() - $start) >= $maxSecs) {
                    break;
                }
                $raw = Redis::lrange($key, $cursor, $cursor + 199);
                if ($raw) {
                    foreach ($raw as $blob) {
                        $cursor++;
                        echo "data: {$blob}\n\n";
                    }
                    @ob_flush();
                    @flush();
                    $lastBeat = time();
                    continue;
                }
                if ((time() - $lastBeat) >= $beat) {
                    echo ": keep-alive\n\n";
                    @ob_flush();
                    @flush();
                    $lastBeat = time();
                }
                usleep(400_000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * Compose a reply job server-side and push it to the worker queue. Only
     * conversation text + (opt-in) prior-session summaries travel — never secrets.
     */
    private function dispatchReply(ChatSession $session): void
    {
        $turns = ChatMessage::where('session_id', $session->id)
            ->orderBy('created_at')->limit(20)
            ->get(['sender', 'content'])
            ->map(fn ($m) => ['role' => $m->sender, 'content' => $m->content])->all();

        $job = [
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
