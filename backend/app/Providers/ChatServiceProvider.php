<?php

namespace App\Providers;

use App\Services\Chat\Capabilities\BibleStudyCapability;
use App\Services\Chat\Capabilities\CounselingCapability;
use App\Services\Chat\Capabilities\PastorChatCapability;
use App\Services\Chat\Capabilities\PrayerCapability;
use App\Services\Chat\Capabilities\WorshipCapability;
use App\Services\Chat\CapabilityResolver;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\Contracts\ChatTelemetry;
use App\Services\Chat\Contracts\ConversationStore;
use App\Services\Chat\Contracts\InputGuardrail;
use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Chat\Contracts\LanguageDetector;
use App\Services\Chat\Contracts\OutputGuardrail;
use App\Services\Chat\Contracts\PromptBuilder;
use App\Services\Chat\Support\CapabilityPromptBuilder;
use App\Services\Chat\Support\HeuristicLanguageDetector;
use App\Services\Chat\Support\HistoryConversationStore;
use App\Services\Chat\Support\LogChatTelemetry;
use App\Services\Chat\Support\NullKnowledgeRetriever;
use App\Services\Inference\InferenceGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the Chat Orchestrator layer. Each seam is bound to its default concrete; the two
 * not-yet-built layers use Null Object / policy defaults that are swapped in ONE place
 * when their real implementations land:
 *
 *   KnowledgeRetriever → NullKnowledgeRetriever          (→ Faiss/Qdrant retriever later)
 *   OutputGuardrail    → UsernameSanitizingOutputGuardrail (→ composite moderation later)
 *
 * Controllers depend on ChatOrchestrator only; they never resolve these collaborators.
 */
final class ChatServiceProvider extends ServiceProvider
{
    /** @var array<class-string,class-string> interface → default concrete */
    public array $bindings = [
        LanguageDetector::class  => HeuristicLanguageDetector::class,
        PromptBuilder::class     => CapabilityPromptBuilder::class,
        KnowledgeRetriever::class => NullKnowledgeRetriever::class,
        ConversationStore::class => HistoryConversationStore::class,
        // InputGuardrail / OutputGuardrail are bound to the guard PIPELINES in
        // GuardrailServiceProvider. The single-guard defaults (CrisisInputGuardrail /
        // UsernameSanitizingOutputGuardrail) remain as a fallback binding option.
    ];

    public function register(): void
    {
        $this->app->singleton(ChatTelemetry::class, fn ($app) => new LogChatTelemetry(
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(CapabilityResolver::class, fn ($app) => new CapabilityResolver([
            $app->make(PastorChatCapability::class),
            $app->make(BibleStudyCapability::class),
            $app->make(PrayerCapability::class),
            $app->make(CounselingCapability::class),
            $app->make(WorshipCapability::class),
        ]));

        $this->app->singleton(ChatOrchestrator::class, fn ($app) => new ChatOrchestrator(
            capabilities: $app->make(CapabilityResolver::class),
            language: $app->make(LanguageDetector::class),
            conversation: $app->make(ConversationStore::class),
            inputGuard: $app->make(InputGuardrail::class),
            knowledge: $app->make(KnowledgeRetriever::class),
            prompt: $app->make(PromptBuilder::class),
            inference: $app->make(InferenceGateway::class),
            outputGuard: $app->make(OutputGuardrail::class),
            telemetry: $app->make(ChatTelemetry::class),
            events: $app->make(Dispatcher::class),
            turnTimeoutSeconds: (int) config('chat.turn_timeout', 90),
            tracer: $app->make(\App\Services\Observability\Contracts\Tracer::class),
        ));
    }
}
