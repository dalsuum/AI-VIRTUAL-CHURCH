<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendStudyChatRequest;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\Data\ChatRequest;
use App\Services\Chat\Exceptions\ChatTimeoutException;
use App\Services\Chat\Exceptions\UnknownCapabilityException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first end-to-end slice through the new AI platform: HTTP → ChatOrchestrator →
 * (Guardrails + Knowledge + Inference) → Response. The controller is intentionally thin and
 * depends ONLY on ChatOrchestrator — it never touches guards, retrieval, prompts or providers.
 * This is the controlled vertical slice: one capability (Bible Study), one corpus (bible),
 * synchronous JSON, structured per-stage tracing already emitted by the orchestrator.
 */
final class ChatController extends Controller
{
    public function __construct(private readonly ChatOrchestrator $chat) {}

    public function study(SendStudyChatRequest $request): JsonResponse
    {
        $chatRequest = new ChatRequest(
            user: $request->user(),
            sessionType: 'bible_study',           // → BibleStudyCapability (uses the bible corpus)
            message: (string) $request->validated('message'),
            sessionId: $request->validated('session_id'),
            correlationId: (string) Str::uuid(),  // server-issued trace id, threaded through every stage
        );

        try {
            $response = $this->chat->handle($chatRequest);
        } catch (UnknownCapabilityException) {
            return response()->json(['error' => 'capability_unavailable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ChatTimeoutException) {
            return response()->json(['error' => 'timeout'], Response::HTTP_GATEWAY_TIMEOUT);
        }

        // A blocked turn is still a 200 with a safe message — the client renders it normally.
        return response()->json($response->toArray());
    }
}
