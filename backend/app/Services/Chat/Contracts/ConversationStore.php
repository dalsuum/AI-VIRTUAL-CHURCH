<?php

namespace App\Services\Chat\Contracts;

use App\Models\ChatSession;
use App\Services\Chat\Data\ChatRequest;
use App\Services\Inference\Data\InferenceResponse;

/**
 * Session lifecycle + history persistence, abstracted so the orchestrator depends on an
 * interface rather than HistoryService/SessionStateStore directly. The default adapter
 * delegates to those existing services (single write entrypoint preserved); the
 * orchestrator never touches Eloquent models or the SessionStateStore itself.
 */
interface ConversationStore
{
    /** Load the owner-scoped session named in the request, or create a new one. */
    public function loadOrCreateSession(ChatRequest $request): ChatSession;

    /**
     * Recent turns oldest→newest for prompt context.
     * @return list<array{sender:string,content:string}>
     */
    public function history(ChatSession $session, int $limit): array;

    /** Persist the user's message turn. Returns the node id. */
    public function recordUserMessage(ChatSession $session, string $text): string;

    /** Persist the assistant's reply turn (with token usage metadata). Returns node id. */
    public function recordAssistantMessage(ChatSession $session, string $text, InferenceResponse $inference): string;
}
