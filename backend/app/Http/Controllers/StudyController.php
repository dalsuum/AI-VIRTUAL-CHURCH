<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateStudySessionRequest;
use App\Http\Requests\PostStudyMessageRequest;
use App\Models\AiPersona;
use App\Models\ModuleManifest;
use App\Models\StudyMessage;
use App\Models\StudySession;
use App\Services\StudyDispatchService;
use App\Services\StudyInputGuard;
use App\Services\StudyTiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worshipper-facing AI Bible Study API (/api/v1/study/*). All session access is
 * owner-scoped; the live stream additionally verifies a hash-only stream token.
 * User text is only ever conversation data.
 */
class StudyController extends Controller
{
    public function __construct(
        private readonly StudyDispatchService $dispatch,
        private readonly StudyInputGuard $guard,
    ) {}

    /** Public-safe config: enabled languages, agent bounds, and public persona names.
     *  Agent bounds are tier-aware for the (optionally authenticated) caller. */
    public function config(Request $request): JsonResponse
    {
        $manifest = ModuleManifest::where('key', config('bible_study.module'))->first();
        abort_unless($manifest && $manifest->isActive(), 503);

        // Resolve the caller (cookie/token) even though this route is public, so the
        // slider max reflects their tier. Falls back to the guest cap when anonymous.
        $user = auth('sanctum')->user();
        $tierMax = StudyTiers::maxFor($user);

        $personas = [];
        foreach ($manifest->languages ?? [] as $lang) {
            $personas[$lang] = AiPersona::forModuleLanguage($manifest->key, $lang)
                ->where('is_moderator', false)
                ->get()
                // toArray() honours $hidden — system_prompt/tradition_tag never leak.
                ->map(fn (AiPersona $p) => [
                    'display_name' => $p->display_name,
                    'avatar_ref'   => $p->avatar_ref,
                ])->values();
        }

        $min = max(ModuleManifest::AGENT_COUNT_MIN, (int) $manifest->min_agent_count);

        return response()->json([
            'languages'           => $manifest->languages,
            // Default starts at the floor (2) but never above the caller's tier cap.
            'default_agent_count' => min(max($min, (int) $manifest->default_agent_count), $tierMax),
            'min_agent_count'     => min($min, $tierMax),
            'max_agent_count'     => $tierMax,                 // tier-capped, server-authoritative
            'tier'                => StudyTiers::tierForUser($user),
            'personas'            => $personas,
        ]);
    }

    /** Start a session, post the first question, dispatch round 1. */
    public function createSession(CreateStudySessionRequest $request): JsonResponse
    {
        $user = $request->user();
        $question = trim($request->string('question'));

        [$ok, $reason] = $this->guard->check($question);
        abort_if(! $ok, 422, $reason);

        $manifest = ModuleManifest::where('key', config('bible_study.module'))->first();

        $session = new StudySession([
            'user_id'       => $user->id,
            'language'      => $request->string('language'),
            'translation'   => $request->string('translation'),
            'style'         => $request->input('style'),
            'topic'         => mb_substr($question, 0, 160),
            'agent_count'   => $manifest->clampAgentCount((int) $request->integer('agent_count')),
            'state'         => 'created',
            'contact_email' => $request->input('contact_email'),
            'last_activity_at' => now(),
        ]);
        $plaintextToken = $session->issueStreamToken();
        $session->owner_fingerprint = StudySession::fingerprint(
            "u:{$user->id}", $request->userAgent(), $request->ip()
        );
        $session->save();

        StudyMessage::create([
            'session_id' => $session->id,
            'turn'       => 1,
            'role'       => 'user',
            'content'    => $question,
        ]);

        $session->update(['state' => 'discussing']);
        $this->dispatch->dispatchRound($session, $question);

        return response()->json([
            'session'      => $session->only(['id', 'language', 'translation', 'style', 'agent_count', 'state']),
            'stream_token' => $plaintextToken,   // returned ONCE — never stored in plaintext
        ], 201);
    }

    /** Post a follow-up question; dispatch a new round. */
    public function postMessage(PostStudyMessageRequest $request, StudySession $session): JsonResponse
    {
        $this->authorizeOwner($request, $session);

        $content = trim($request->string('content'));
        [$ok, $reason] = $this->guard->check($content);
        abort_if(! $ok, 422, $reason);

        $turn = (int) StudyMessage::where('session_id', $session->id)->max('turn') + 1;
        StudyMessage::create([
            'session_id' => $session->id,
            'turn'       => $turn,
            'role'       => 'user',
            'content'    => $content,
        ]);

        $session->update(['state' => 'discussing', 'last_activity_at' => now()]);
        $this->dispatch->dispatchRound($session, $content);

        return response()->json(['ok' => true, 'turn' => $turn]);
    }

    /** Reconnect replay: persisted events with seq > after_seq, owner-scoped + bounded. */
    public function listEvents(Request $request, StudySession $session): JsonResponse
    {
        $this->authorizeOwner($request, $session);

        $after = max(0, (int) $request->integer('after_seq'));
        $gap = (int) config('bible_study.replay_max_gap', 10000);
        $end = $after + $gap;

        $key = config('bible_study.module') . ":{$session->id}:events";
        $raw = Redis::lrange($key, $after, $end - 1);

        $events = [];
        foreach ($raw as $blob) {
            $decoded = json_decode($blob, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return response()->json(['events' => $events]);
    }

    /** Live SSE stream — verifies stream token + ownership + idle TTL + concurrency cap. */
    public function stream(Request $request, StudySession $session): StreamedResponse
    {
        $this->authorizeOwner($request, $session);

        $token = (string) $request->query('token', '');
        abort_unless($token !== '' && $session->verifyStreamToken($token), 403, 'Invalid stream token.');

        if ($session->last_activity_at && $session->last_activity_at->diffInSeconds(now()) > config('bible_study.idle_ttl')) {
            abort(410, 'Stream expired.');
        }

        // Soft fingerprint: mismatch is logged, never a hard block (mobile NAT/VPN).
        $fp = StudySession::fingerprint("u:{$request->user()->id}", $request->userAgent(), $request->ip());
        if ($session->owner_fingerprint && ! hash_equals($session->owner_fingerprint, $fp)) {
            logger()->warning('study.stream.fingerprint_mismatch', ['session' => $session->id]);
        }

        // Hard per-user concurrent-stream cap so tabs/retries can't exhaust FPM.
        $countKey = config('bible_study.module') . ':streams:' . $request->user()->id;
        if ((int) Redis::get($countKey) >= (int) config('bible_study.max_concurrent')) {
            abort(429, 'Too many open study streams.');
        }

        $logKey   = config('bible_study.module') . ":{$session->id}:events";
        $after    = max(0, (int) $request->query('after_seq', 0));
        $maxSecs  = (int) config('bible_study.stream_max_seconds');
        $beat     = (int) config('bible_study.heartbeat_seconds');

        return new StreamedResponse(function () use ($logKey, $after, $maxSecs, $beat, $countKey) {
            Redis::incr($countKey);
            Redis::expire($countKey, $maxSecs + 60);
            $start = time();
            $cursor = $after;
            $lastBeat = time();

            try {
                while (true) {
                    if (connection_aborted() || (time() - $start) >= $maxSecs) {
                        break;
                    }
                    $raw = Redis::lrange($logKey, $cursor, $cursor + 199);
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
                    usleep(400_000); // 0.4s poll
                }
            } finally {
                Redis::decr($countKey);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no', // disable nginx proxy buffering for SSE
            'Connection'        => 'keep-alive',
        ]);
    }

    /** End the discussion and dispatch the summary generation. */
    public function endSession(Request $request, StudySession $session): JsonResponse
    {
        $this->authorizeOwner($request, $session);

        if (! in_array($session->state, ['summarized', 'closed'], true)) {
            $session->update(['state' => 'ending']);
            $this->dispatch->dispatchSummary($session);
        }

        return response()->json(['ok' => true, 'state' => $session->state]);
    }

    /** Read a session with its messages + summary (owner-scoped). */
    public function show(Request $request, StudySession $session): JsonResponse
    {
        $this->authorizeOwner($request, $session);
        $session->load(['messages' => fn ($q) => $q->orderBy('turn'), 'summary']);

        return response()->json($session);
    }

    private function authorizeOwner(Request $request, StudySession $session): void
    {
        abort_unless(
            $session->user_id !== null && (int) $session->user_id === (int) $request->user()->id,
            403
        );
    }
}
