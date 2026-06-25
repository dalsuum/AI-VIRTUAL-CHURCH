<?php

namespace App\Services\Inference\Exceptions;

/**
 * Thrown when a provider's circuit breaker is OPEN — the provider has failed enough
 * recently that we fast-fail instead of paying for another timeout. Never retryable
 * on the same provider; the gateway treats it as a signal to try the fallback chain.
 */
final class CircuitOpenException extends ProviderException
{
    public function __construct(string $provider)
    {
        parent::__construct($provider, "Circuit open for provider [{$provider}]", retryable: false);
    }
}
