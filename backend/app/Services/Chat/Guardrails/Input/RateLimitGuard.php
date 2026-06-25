<?php

namespace App\Services\Chat\Guardrails\Input;

use App\Services\Chat\Data\ChatContext;
use App\Services\Chat\Data\GuardrailVerdict;
use App\Services\Chat\Guardrails\Contracts\InputGuard;
use App\Services\Chat\Guardrails\Contracts\PolicyRepository;
use Illuminate\Cache\RateLimiter;

/**
 * Per-user, per-capability request throttle — an abuse/cost control independent of token
 * balance (OWASP API04 / LLM10). Keys on the authenticated user id from the ChatContext,
 * NEVER an HTTP request or IP (guards must not know about the transport). Limits live in
 * the 'rate' policy so they can be tuned per environment without code changes.
 */
final class RateLimitGuard implements InputGuard
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly PolicyRepository $policies,
    ) {}

    public function key(): string
    {
        return 'rate_limit';
    }

    public function inspect(ChatContext $context): GuardrailVerdict
    {
        $policy = $this->policies->get('rate', ['max' => 30, 'decay' => 60]);
        $max = (int) ($policy['max'] ?? 30);
        $decay = (int) ($policy['decay'] ?? 60);

        $bucket = sprintf('chat:%d:%s', $context->request->user->id, $context->capability->key());

        if ($this->limiter->tooManyAttempts($bucket, $max)) {
            return GuardrailVerdict::block(
                'rate_limited',
                (string) ($policy['safe_message'] ?? 'You are sending messages a little fast. Please pause a moment and try again.'),
            );
        }

        $this->limiter->hit($bucket, $decay);

        return GuardrailVerdict::allow();
    }
}
