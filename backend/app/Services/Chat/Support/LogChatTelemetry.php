<?php

namespace App\Services\Chat\Support;

use App\Services\Chat\Contracts\ChatTelemetry;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\ChatResponse;
use Psr\Log\LoggerInterface;

/**
 * Default telemetry sink: structured logs keyed by correlation id, carrying timings and
 * outcomes but NEVER message bodies or secrets — so logs are safe to ship to Loki/ELK.
 * The LoggerInterface is injected (no Log facade), keeping the layer free of static
 * helpers and trivially fakeable in tests.
 */
final class LogChatTelemetry implements ChatTelemetry
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function started(ChatContext $context): void
    {
        $this->log->info('chat.started', $this->base($context));
    }

    public function stepTimed(ChatContext $context, string $step, int $millis): void
    {
        $this->log->debug('chat.step', $this->base($context) + ['step' => $step, 'ms' => $millis]);
    }

    public function knowledgeRetrieved(ChatContext $context, string $reason, float $confidence, int $snippets, int $millis): void
    {
        $this->log->info('chat.knowledge', $this->base($context) + [
            'reason'     => $reason,
            'confidence' => $confidence,
            'snippets'   => $snippets,
            'ms'         => $millis,
        ]);
    }

    public function completed(ChatContext $context, ChatResponse $response): void
    {
        $this->log->info('chat.completed', $this->base($context) + [
            'blocked'    => $response->blocked,
            'provider'   => $response->provider,
            'latency_ms' => $response->latencyMs,
        ]);
    }

    public function failed(ChatContext $context, \Throwable $error): void
    {
        $this->log->error('chat.failed', $this->base($context) + [
            'error' => $error::class,
            'message' => $error->getMessage(),
        ]);
    }

    /** @return array<string,mixed> */
    private function base(ChatContext $context): array
    {
        return [
            'correlation_id' => $context->request->correlationId,
            'capability'     => $context->request->sessionType,
            'language'       => $context->language,
            'user_id'        => $context->request->user->id,
        ];
    }
}
