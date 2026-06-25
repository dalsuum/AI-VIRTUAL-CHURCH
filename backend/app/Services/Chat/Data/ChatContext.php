<?php

namespace App\Services\Chat\Data;

use App\Models\ChatSession;
use App\Services\Chat\Contracts\ChatCapability;
use App\Services\Chat\Support\Deadline;
use App\Services\Inference\Data\InferenceResponse;

/**
 * The orchestrator's internal working state for a single turn. It is NOT a public DTO —
 * it never leaves the orchestrator (controllers get a ChatResponse). Each pipeline step
 * fills one more field, so the steps stay small and ordered while sharing one carrier.
 *
 * Kept as a simple mutable object on purpose: threading 13 immutable `with…()` copies
 * through the pipeline would add noise without safety, since the context is single-owner
 * and single-threaded per request.
 */
final class ChatContext
{
    public ChatSession $session;
    /** @var list<array{sender:string,content:string}> */
    public array $history = [];
    public string $language;
    public ChatCapability $capability;
    public KnowledgeContext $knowledge;
    public ?InferenceResponse $inference = null;
    public string $finalText = '';

    public function __construct(
        public readonly ChatRequest $request,
        public readonly Deadline $deadline,
        public readonly CancellationToken $cancellation,
    ) {
        $this->knowledge = KnowledgeContext::empty();
        $this->language = $request->languageHint ?? 'en';
    }
}
