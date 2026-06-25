<?php

namespace App\Services\Inference\Exceptions;

/**
 * Thrown by the gateway when every provider in the resolved fallback chain has failed
 * or is circuit-open. This is the only inference failure the orchestration layer above
 * should ever have to handle.
 */
final class NoProviderAvailableException extends \RuntimeException
{
    /** @param list<string> $tried */
    public function __construct(public readonly array $tried, ?\Throwable $previous = null)
    {
        parent::__construct(
            'No inference provider available (tried: ' . implode(', ', $tried) . ')',
            0,
            $previous,
        );
    }
}
