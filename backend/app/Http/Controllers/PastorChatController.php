<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Services\GuestUsageService;
use App\Services\HistoryService;
use App\Services\PastorReplyDispatcher;
use App\Services\Pipeline\Pastor\PastorChatPipeline;
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
        private readonly PastorReplyDispatcher $replies,
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

    /**
     * Start a Pastor Chat: charge a token, post the first message, dispatch a reply.
     * The hard path (validate → create session → reserve → dispatch → commit/rollback)
     * is owned by the shared pipeline. See App\Services\Pipeline\Pastor\PastorChatPipeline.
     */
    public function start(Request $request): JsonResponse
    {
        return app(PastorChatPipeline::class)->handle($request);
    }

    /** Post a follow-up message and dispatch a new reply. */
    public function postMessage(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $this->history->recordMessage($session, 'user', trim($data['message']));
        $this->replies->dispatch($session);

        return response()->json(['ok' => true]);
    }

    /** Owner-scoped message history for hydration/resume. */
    public function messages(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);

        // Phase 4: messages live as message-type nodes in session_nodes (the legacy
        // chat_messages relation was dropped) — read them through the state store.
        $messages = app(\App\Services\SessionState\SessionStateStore::class)
            ->messageDtos($session)
            ->map(fn ($m) => [
                'sender'     => $m['sender'],
                'content'    => $m['content'],
                'created_at' => $m['created_at'],
            ])->values();

        return response()->json(['messages' => $messages]);
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
}
