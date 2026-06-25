<?php

namespace Tests\Unit\Chat;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\Chat\Capabilities\BibleStudyCapability;
use App\Services\Chat\Capabilities\PastorChatCapability;
use App\Services\Chat\CapabilityResolver;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\Contracts\ConversationStore;
use App\Services\Chat\Contracts\InputGuardrail;
use App\Services\Chat\Contracts\KnowledgeRetriever;
use App\Services\Chat\Contracts\LanguageDetector;
use App\Services\Chat\Contracts\OutputGuardrail;
use App\Services\Chat\Contracts\PromptBuilder;
use App\Services\Chat\Contracts\ChatTelemetry;
use App\Services\Chat\Data\CancellationToken;
use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\ChatRequest;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Data\KnowledgeContext;
use App\Services\Chat\Exceptions\ChatCancelledException;
use App\Services\Chat\Support\CapabilityPromptBuilder;
use App\Services\Inference\Data\InferenceRequest;
use App\Services\Inference\Data\InferenceResponse;
use App\Services\Inference\Data\TokenUsage;
use App\Services\Inference\InferenceGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Orchestrator unit tests with every collaborator faked — no DB, no network, no container.
 * Proves the 13-step coordination: ordering, input/output guardrail short-circuits, output
 * sanitisation, persistence calls, and cooperative cancellation.
 */
class ChatOrchestratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function session(): ChatSession
    {
        $s = new ChatSession(['session_type' => 'pastor', 'language' => 'en']);
        $s->id = 'sess-1';

        return $s;
    }

    private function request(string $type = 'pastor', string $msg = 'I feel low'): ChatRequest
    {
        $user = new User(['name' => 'Mary']);
        $user->id = 7;

        return new ChatRequest($user, $type, $msg, correlationId: 'cid-1');
    }

    private function fakeStore(ChatSession $session, array &$recorded): ConversationStore
    {
        return new class($session, $recorded) implements ConversationStore {
            public function __construct(private ChatSession $s, private array &$rec) {}
            public function loadOrCreateSession(ChatRequest $r): ChatSession { return $this->s; }
            public function history(ChatSession $s, int $l): array { return []; }
            public function recordUserMessage(ChatSession $s, string $t): string { $this->rec[] = "user:$t"; return 'n1'; }
            public function recordAssistantMessage(ChatSession $s, string $t, InferenceResponse $i): string { $this->rec[] = "asst:$t"; return 'n2'; }
        };
    }

    private function fakeLang(): LanguageDetector
    {
        return new class implements LanguageDetector {
            public function detect(string $t, ?string $h = null): string { return $h ?? 'en'; }
        };
    }

    private function allowInput(): InputGuardrail
    {
        return new class implements InputGuardrail {
            public function inspect(ChatContext $c): GuardrailVerdict { return GuardrailVerdict::allow(); }
        };
    }

    private function nullKnowledge(): KnowledgeRetriever
    {
        return new class implements KnowledgeRetriever {
            public function retrieve(string $q, array $f = []): KnowledgeContext { return KnowledgeContext::empty(); }
        };
    }

    private function passOutput(): OutputGuardrail
    {
        return new class implements OutputGuardrail {
            public function review(string $o, ChatContext $c): GuardrailVerdict { return GuardrailVerdict::allow($o); }
        };
    }

    private function telemetry(): ChatTelemetry
    {
        return new class implements ChatTelemetry {
            public function started(ChatContext $c): void {}
            public function stepTimed(ChatContext $c, string $s, int $m): void {}
            public function knowledgeRetrieved(ChatContext $c, string $r, float $conf, int $n, int $m): void {}
            public function completed(ChatContext $c, $r): void {}
            public function failed(ChatContext $c, \Throwable $e): void {}
        };
    }

    private function gatewayReturning(string $text): InferenceGateway
    {
        $gw = Mockery::mock(InferenceGateway::class);
        $gw->shouldReceive('complete')->andReturn(
            new InferenceResponse($text, 'claude', 'claude-sonnet-4-6', new TokenUsage(10, 5), 42),
        );

        return $gw;
    }

    private function build(
        ConversationStore $store,
        InferenceGateway $gateway,
        ?InputGuardrail $input = null,
        ?OutputGuardrail $output = null,
    ): ChatOrchestrator {
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch');

        return new ChatOrchestrator(
            capabilities: new CapabilityResolver([new PastorChatCapability(), new BibleStudyCapability()]),
            language: $this->fakeLang(),
            conversation: $store,
            inputGuard: $input ?? $this->allowInput(),
            knowledge: $this->nullKnowledge(),
            prompt: new CapabilityPromptBuilder(),
            inference: $gateway,
            outputGuard: $output ?? $this->passOutput(),
            telemetry: $this->telemetry(),
            events: $events,
        );
    }

    public function test_happy_path_runs_full_pipeline_and_persists(): void
    {
        $recorded = [];
        $store = $this->fakeStore($this->session(), $recorded);
        $orch = $this->build($store, $this->gatewayReturning('Peace be with you.'));

        $res = $orch->handle($this->request());

        $this->assertFalse($res->blocked);
        $this->assertSame('Peace be with you.', $res->text);
        $this->assertSame('pastor', $res->capability);
        $this->assertSame('claude', $res->provider);
        $this->assertSame(['user:I feel low', 'asst:Peace be with you.'], $recorded, 'user then assistant persisted in order');
    }

    public function test_input_guardrail_block_short_circuits_before_inference(): void
    {
        $recorded = [];
        $store = $this->fakeStore($this->session(), $recorded);
        $gateway = Mockery::mock(InferenceGateway::class);
        $gateway->shouldNotReceive('complete'); // never reached

        $block = new class implements InputGuardrail {
            public function inspect(ChatContext $c): GuardrailVerdict { return GuardrailVerdict::block('crisis', 'Please reach out for help.'); }
        };

        $res = $this->build($store, $gateway, input: $block)->handle($this->request());

        $this->assertTrue($res->blocked);
        $this->assertSame('crisis', $res->blockReason);
        $this->assertSame('Please reach out for help.', $res->text);
        $this->assertSame(['user:I feel low'], $recorded, 'user message persisted, no assistant output');
    }

    public function test_output_guardrail_sanitises_username(): void
    {
        $recorded = [];
        $store = $this->fakeStore($this->session(), $recorded);
        // Real username sanitiser strips the account name "Mary".
        $output = new \App\Services\Chat\Support\UsernameSanitizingOutputGuardrail();

        $res = $this->build($store, $this->gatewayReturning('Mary, you are loved.'), output: $output)->handle($this->request());

        $this->assertFalse($res->blocked);
        $this->assertStringNotContainsString('Mary', $res->text);
        $this->assertSame('you are loved.', $res->text);
    }

    public function test_cancellation_before_inference_aborts(): void
    {
        $recorded = [];
        $store = $this->fakeStore($this->session(), $recorded);
        $gateway = Mockery::mock(InferenceGateway::class);
        $gateway->shouldNotReceive('complete');

        $cancelled = new CancellationToken(fn () => true);

        $this->expectException(ChatCancelledException::class);
        $this->build($store, $gateway)->handle($this->request(), $cancelled);
    }
}
