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

    /** Owner — or, for a GROUP pastor room (v1.4), any active member of the group.
     *  Members read along and speak into the same conversation; replies still ride
     *  the owner's session (creator-pays, the owner decision from study rooms). */
    private function findParticipant(Request $request, string $id): ChatSession
    {
        $session = ChatSession::query()
            ->where('session_type', 'pastor')
            ->whereKey($id)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));

        $user = $request->user();
        if ((int) $session->user_id === (int) $user->id) {
            return $session;
        }
        if ($session->group_id
            && $user->hasGroupRole((int) $session->group_id, \App\Enums\GroupRole::MEMBER)) {
            return $session;
        }

        abort(Response::HTTP_NOT_FOUND);
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

    /** Post a follow-up message and dispatch a new reply. In a GROUP room every
     *  member's message runs the crisis intercept INDIVIDUALLY — on a trigger the
     *  static resource goes back privately to the sender and nothing enters the
     *  shared conversation or reaches the AI (a safety boundary, per person). */
    public function postMessage(Request $request, string $id): JsonResponse
    {
        $session = $this->findParticipant($request, $id);
        $data = $request->validate(['message' => ['required', 'string', 'max:4000']]);
        $text = trim($data['message']);
        $isRoom = (bool) $session->group_id;

        if ($isRoom) {
            $crisis = app(\App\Services\CrisisInterceptService::class)->inspect($session->id, $text);
            if ($crisis['intercepted']) {
                return response()->json([
                    'ok' => true, 'intercepted' => true, 'resource' => $crisis['resource'],
                ]);
            }
        }

        $this->history->recordMessage($session, 'user', $text, $isRoom ? [
            // Attribution: in a shared room the AI pastor and the members should
            // know who is speaking (this is conversation data, not service text).
            'metadata' => [
                'sender_id'   => $request->user()->id,
                'sender_name' => $request->user()->name,
            ],
        ] : []);
        $this->replies->dispatch($session);

        return response()->json(['ok' => true]);
    }

    /** Message history for hydration/resume — owner or group-room member. */
    public function messages(Request $request, string $id): JsonResponse
    {
        $session = $this->findParticipant($request, $id);

        // Phase 4: messages live as message-type nodes in session_nodes (the legacy
        // chat_messages relation was dropped) — read them through the state store.
        $messages = app(\App\Services\SessionState\SessionStateStore::class)
            ->messageDtos($session)
            ->map(fn ($m) => [
                'sender'      => $m['sender'],
                'sender_name' => $m['metadata']['sender_name'] ?? null,
                'content'     => $m['content'],
                'created_at'  => $m['created_at'],
            ])->values();

        return response()->json(['messages' => $messages]);
    }

    /** The caller's recent pastor conversations — feeds the Group Page room-picker. */
    public function mine(Request $request): JsonResponse
    {
        $sessions = ChatSession::forUser((int) $request->user()->id)
            ->where('session_type', 'pastor')
            ->latest('last_activity_at')->limit(10)
            ->get(['id', 'title', 'group_id', 'last_activity_at']);

        return response()->json(['sessions' => $sessions]);
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
