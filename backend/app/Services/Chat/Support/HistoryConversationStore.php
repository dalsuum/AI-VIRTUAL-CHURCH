<?php

namespace App\Services\Chat\Support;

use App\Models\ChatSession;
use App\Services\Chat\Contracts\ConversationStore;
use App\Services\Chat\Data\ChatRequest;
use App\Services\HistoryService;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\SessionState\SessionStateStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default ConversationStore: adapts the existing HistoryService (single write entrypoint:
 * titling, touch, cache invalidation) and SessionStateStore (read of branch turns). The
 * orchestrator therefore reuses the unified history spine without knowing its internals,
 * and ownership is enforced here so a caller can never load another user's session.
 */
final class HistoryConversationStore implements ConversationStore
{
    public function __construct(
        private readonly HistoryService $history,
        private readonly SessionStateStore $state,
    ) {}

    public function loadOrCreateSession(ChatRequest $request): ChatSession
    {
        if ($request->sessionId !== null) {
            return ChatSession::forUser((int) $request->user->id)
                ->where('session_type', $request->sessionType)
                ->whereKey($request->sessionId)
                ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
        }

        return $this->history->startSession($request->user, $request->sessionType, [
            'language' => $request->languageHint,
        ]);
    }

    public function history(ChatSession $session, int $limit): array
    {
        return $this->state->messageTurns($session, $limit);
    }

    public function recordUserMessage(ChatSession $session, string $text): string
    {
        return $this->history->recordMessage($session, 'user', $text);
    }

    public function recordAssistantMessage(ChatSession $session, string $text, InferenceResponse $inference): string
    {
        return $this->history->recordMessage($session, 'assistant', $text, [
            'token_usage' => $inference->usage->total(),
            'metadata'    => [
                'provider'     => $inference->providerName,
                'model'        => $inference->model,
                'prompt_tokens' => $inference->usage->promptTokens,
                'output_tokens' => $inference->usage->completionTokens,
            ],
        ]);
    }
}
