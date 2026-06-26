<?php

namespace App\Providers;

use App\Services\Chat\Contracts\InputGuardrail;
use App\Services\Chat\Contracts\OutputGuardrail;
use App\Services\Chat\Guardrails\ConfigPolicyRepository;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;
use App\Services\Chat\Guardrails\GuardChainResolver;
use App\Services\Chat\Guardrails\Input\AbuseGuard;
use App\Services\Chat\Guardrails\Input\CrisisGuard;
use App\Services\Chat\Guardrails\Input\PiiGuard;
use App\Services\Chat\Guardrails\Input\PromptInjectionGuard;
use App\Services\Chat\Guardrails\Input\RateLimitGuard;
use App\Services\Chat\Guardrails\InputGuardPipeline;
use App\Services\Chat\Guardrails\Output\CitationGuard;
use App\Services\Chat\Guardrails\Output\ContentModerationGuard;
use App\Services\Chat\Guardrails\Output\HallucinationGuard;
use App\Services\Chat\Guardrails\Output\HtmlSanitizerGuard;
use App\Services\Chat\Guardrails\Output\MarkdownSanitizerGuard;
use App\Services\Chat\Guardrails\Output\TheologyConsistencyGuard;
use App\Services\Chat\Guardrails\Output\UsernameSanitizerGuard;
use App\Services\Chat\Guardrails\OutputGuardPipeline;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Guardrails layer and binds the orchestrator's two guardrail seams to the
 * PIPELINE implementations. Because the pipelines implement InputGuardrail/OutputGuardrail,
 * the orchestrator and ChatServiceProvider are untouched — adding the full guard chain was
 * a binding change, exactly as the stable-interface design intended.
 *
 * Guard SETS are assembled here (the only place that knows the concrete guards). Ordering,
 * enablement and policy come from config — never from this provider.
 */
final class GuardrailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PolicyRepository::class, ConfigPolicyRepository::class);
        $this->app->singleton(GuardChainResolver::class);

        $this->app->singleton(InputGuardrail::class, fn ($app) => new InputGuardPipeline(
            guards: [
                $app->make(RateLimitGuard::class),
                $app->make(CrisisGuard::class),
                $app->make(PromptInjectionGuard::class),
                $app->make(AbuseGuard::class),
                $app->make(PiiGuard::class),
            ],
            resolver: $app->make(GuardChainResolver::class),
        ));

        $this->app->singleton(OutputGuardrail::class, fn ($app) => new OutputGuardPipeline(
            guards: [
                $app->make(HtmlSanitizerGuard::class),
                $app->make(MarkdownSanitizerGuard::class),
                $app->make(ContentModerationGuard::class),
                $app->make(HallucinationGuard::class),
                $app->make(CitationGuard::class),
                $app->make(TheologyConsistencyGuard::class),
                $app->make(UsernameSanitizerGuard::class),
            ],
            resolver: $app->make(GuardChainResolver::class),
        ));
    }
}
