<?php

namespace App\Services\Chat;

use App\Services\Chat\Contracts\ConversationStore;
use App\Services\Chat\Contracts\InputGuardrail;
use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Chat\Contracts\LanguageDetector;
use App\Services\Chat\Contracts\OutputGuardrail;
use App\Services\Chat\Contracts\PromptBuilder;
use App\Services\Chat\Contracts\ChatTelemetry;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\ChatRequest;
use App\Services\Chat\Data\ChatResponse;
use App\Services\Chat\Data\CancellationToken;
use App\Services\Chat\Events\ChatBlocked;
use App\Services\Chat\Events\ChatCompleted;
use App\Services\Chat\Exceptions\ChatCancelledException;
use App\Services\Chat\Exceptions\ChatTimeoutException;
use App\Services\Chat\Support\Deadline;
use App\Services\Inference\InferenceGateway;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The Chat Orchestrator — the application's AI brain and the ONLY AI entry point a
 * controller may call. It owns the fixed 13-step pipeline and coordinates every other
 * layer through interfaces; it implements none of their internals (no LLM HTTP, no
 * embeddings, no vector search, no guardrail rules, no Eloquent). Each collaborator is
 * constructor-injected, so the whole brain is unit-testable with fakes and every layer is
 * swappable without touching this class (Dependency Inversion + Open/Closed).
 *
 * Pipeline: receive → load session → load history → detect language → select capability →
 * input guard → retrieve knowledge → build prompt → infer → output guard → persist →
 * telemetry → respond. Cross-cutting: correlation ids, a per-turn deadline, cooperative
 * cancellation, and per-step timing.
 */
final class ChatOrchestrator
{
    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly LanguageDetector $language,
        private readonly ConversationStore $conversation,
        private readonly InputGuardrail $inputGuard,
        private readonly KnowledgeRetriever $knowledge,
        private readonly PromptBuilder $prompt,
        private readonly InferenceGateway $inference,
        private readonly OutputGuardrail $outputGuard,
        private readonly ChatTelemetry $telemetry,
        private readonly Dispatcher $events,
        private readonly int $turnTimeoutSeconds = 90,
        private readonly \App\Services\Observability\Contracts\Tracer $tracer = new \App\Services\Observability\NullTracer(),
    ) {}

    /** Blocking turn. Returns the full ChatResponse (success or safe-blocked). */
    public function handle(ChatRequest $request, ?CancellationToken $cancellation = null): ChatResponse
    {
        // One trace per chat request; deeper layers (retrieval, fusion, rerank) nest under it.
        return $this->tracer->trace($request->correlationId, 'chat.request', function () use ($request, $cancellation) {
            $context = $this->begin($request, $cancellation);

            try {
                // Steps 6 (input guard) may short-circuit before any inference cost.
                if ($blocked = $this->tracer->span('guardrails.pre', fn () => $this->prepareAndGuardInput($context))) {
                    return $blocked;
                }

                // 7–8: knowledge + prompt.
                $this->retrieveKnowledge($context);
                $this->ensureLive($context);
                $built = $this->prompt->build($context);

                // 9: inference.
                $this->ensureLive($context);
                $context->inference = $this->tracer->span('inference.llm', fn () => $this->time($context, 'inference', fn () => $this->inference->complete($built)));
                $this->tracer->annotate([
                    'inference.provider'   => $context->inference->providerName,
                    'inference.model'      => $context->inference->model,
                    'inference.latency_ms' => $context->inference->latencyMs,
                ]);

                // 10: output guard.
                $verdict = $this->tracer->span('guardrails.post', fn () => $this->outputGuard->review($context->inference->text, $context));
                if (! $verdict->allowed) {
                    return $this->finishBlocked($context, 'output', $verdict->reason ?? 'policy', $verdict->safeMessage ?? '');
                }
                $context->finalText = $verdict->text ?? $context->inference->text;

                // 11–13: persist, telemetry, respond.
                return $this->finishOk($context);
            } catch (\Throwable $e) {
                $this->telemetry->failed($context, $e);
                throw $e;
            }
        }, ['route' => $request->sessionType, 'user_id' => (int) $request->user->id]);
    }

    /**
     * Streaming turn. Yields text deltas as they arrive and RETURNS the final ChatResponse.
     * Output guardrails run on the ASSEMBLED text after the stream closes (per-token guarding
     * belongs on the worker that owns the token stream); a blocked verdict still persists a
     * safe message and is reflected in the returned response.
     *
     * @return \Generator<int,string,mixed,ChatResponse>
     */
    public function stream(ChatRequest $request, ?CancellationToken $cancellation = null): \Generator
    {
        $context = $this->begin($request, $cancellation);

        try {
            if ($blocked = $this->prepareAndGuardInput($context)) {
                yield $blocked->text;

                return $blocked;
            }

            $this->retrieveKnowledge($context);
            $this->ensureLive($context);
            $built = $this->prompt->build($context);

            $this->ensureLive($context);
            $stream = $this->inference->stream($built);

            foreach ($stream as $delta) {
                $this->ensureLive($context); // cooperative cancel between chunks
                yield $delta;
            }
            $context->inference = $stream->getReturn();

            $verdict = $this->outputGuard->review($context->inference->text, $context);
            if (! $verdict->allowed) {
                return $this->finishBlocked($context, 'output', $verdict->reason ?? 'policy', $verdict->safeMessage ?? '');
            }
            $context->finalText = $verdict->text ?? $context->inference->text;

            return $this->finishOk($context);
        } catch (\Throwable $e) {
            $this->telemetry->failed($context, $e);
            throw $e;
        }
    }

    // ── pipeline phases ───────────────────────────────────────────────────────

    private function begin(ChatRequest $request, ?CancellationToken $cancellation): ChatContext
    {
        $context = new ChatContext(
            $request,
            Deadline::in($this->turnTimeoutSeconds),
            $cancellation ?? CancellationToken::none(),
        );
        $this->telemetry->started($context);

        return $context;
    }

    /** Steps 2–6. Returns a blocked ChatResponse if the input guardrail short-circuits. */
    private function prepareAndGuardInput(ChatContext $context): ?ChatResponse
    {
        // 2: session
        $context->session = $this->time($context, 'session', fn () => $this->conversation->loadOrCreateSession($context->request));
        // 3: history
        $context->history = $this->time($context, 'history', fn () => $this->conversation->history($context->session, 20));
        // 4: language (explicit hint wins; otherwise detect from the message)
        $context->language = $this->language->detect($context->request->message, $context->request->languageHint ?? $context->session->language);
        // 5: capability
        $context->capability = $this->capabilities->resolve($context->request->sessionType);

        // The user message is conversation data — persist it before guarding so history is
        // coherent even when the turn is blocked or inference fails.
        $this->conversation->recordUserMessage($context->session, $context->request->message);

        $this->ensureLive($context);

        // 6: input guardrail
        $verdict = $this->time($context, 'input_guard', fn () => $this->inputGuard->inspect($context));
        if (! $verdict->allowed) {
            return $this->finishBlocked($context, 'input', $verdict->reason ?? 'policy', $verdict->safeMessage ?? '');
        }

        return null;
    }

    private function retrieveKnowledge(ChatContext $context): void
    {
        if (! $context->capability->usesKnowledge()) {
            return;
        }
        $query = $context->capability->knowledgeQuery($context);
        if ($query === null) {
            return;
        }

        $start = microtime(true);
        try {
            $context->knowledge = $this->knowledge->retrieve($query, ['language' => $context->language]);
        } catch (\Throwable $e) {
            // Anti-cascade: retrieval is an enrichment, never a hard dependency of a chat turn.
            // Even a misbehaving retriever degrades to a classified FAILURE context, never an error.
            $this->telemetry->failed($context, $e);
            $context->knowledge = \App\Services\Chat\Data\KnowledgeContext::failure();
        }
        $ms = (int) ((microtime(true) - $start) * 1000);

        // Emit the per-stage knowledge trace (reason/confidence/count) — the observability the
        // grounded surfaces need to be debuggable.
        $this->telemetry->knowledgeRetrieved(
            $context,
            $context->knowledge->reason,
            $context->knowledge->confidence,
            count($context->knowledge->snippets),
            $ms,
        );
    }

    /** Steps 11–13 for a successful turn. */
    private function finishOk(ChatContext $context): ChatResponse
    {
        $inference = $context->inference;
        $this->tracer->span('persistence.write', fn () => $this->conversation->recordAssistantMessage($context->session, $context->finalText, $inference));

        $response = new ChatResponse(
            sessionId: $context->session->id,
            text: $context->finalText,
            language: $context->language,
            capability: $context->capability->key(),
            correlationId: $context->request->correlationId,
            provider: $inference->providerName,
            model: $inference->model,
            promptTokens: $inference->usage->promptTokens,
            completionTokens: $inference->usage->completionTokens,
            latencyMs: $inference->latencyMs,
        );

        $this->telemetry->completed($context, $response);
        $this->events->dispatch(new ChatCompleted(
            $context->session->id, (int) $context->request->user->id, $context->capability->key(), $response,
        ));

        return $response;
    }

    /** Persist a safe message + emit a block event; shared by input and output blocks. */
    private function finishBlocked(ChatContext $context, string $stage, string $reason, string $safeMessage): ChatResponse
    {
        // The blocked turn charges no inference and persists no model output; the user's
        // message was already recorded in prepareAndGuardInput(). The safe message travels
        // in the response only, keeping a blocked turn free of any generated content.
        $response = ChatResponse::blocked(
            $context->session->id,
            $context->language,
            $context->capability->key(),
            $context->request->correlationId,
            $safeMessage,
            $reason,
        );

        $this->telemetry->completed($context, $response);
        $this->events->dispatch(new ChatBlocked(
            $context->session->id, (int) $context->request->user->id, $stage, $reason, $context->request->correlationId,
        ));

        return $response;
    }

    // ── cross-cutting ─────────────────────────────────────────────────────────

    /** Abort promptly on deadline or cancellation between steps/chunks. */
    private function ensureLive(ChatContext $context): void
    {
        if ($context->cancellation->isCancelled()) {
            throw new ChatCancelledException('Chat turn cancelled by client.');
        }
        if ($context->deadline->exceeded()) {
            throw new ChatTimeoutException('Chat turn exceeded its time budget.');
        }
    }

    /** @template T  @param callable():T $fn  @return T */
    private function time(ChatContext $context, string $step, callable $fn): mixed
    {
        $start = microtime(true);
        try {
            return $fn();
        } finally {
            $this->telemetry->stepTimed($context, $step, (int) ((microtime(true) - $start) * 1000));
        }
    }
}
