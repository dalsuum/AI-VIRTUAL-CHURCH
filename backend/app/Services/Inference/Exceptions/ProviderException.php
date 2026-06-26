<?php

namespace App\Services\Inference\Exceptions;

/**
 * Base class for inference-layer failures. `retryable` tells the resilient decorator
 * whether another attempt (or a fallback provider) is worth trying: transport/5xx/
 * timeout are retryable; a 4xx model rejection or a tripped circuit is not.
 */
class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly bool $retryable = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
