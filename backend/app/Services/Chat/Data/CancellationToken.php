<?php

namespace App\Services\Chat\Data;

/**
 * Cooperative cancellation. The orchestrator checks isCancelled() between pipeline steps
 * and between streamed chunks, so a disconnected client or an admin abort stops work
 * promptly without killing the process. Modelled as an injected predicate rather than a
 * static flag so it is trivially testable and never global.
 */
final class CancellationToken
{
    /** @param (callable():bool)|null $predicate */
    public function __construct(private $predicate = null) {}

    /** A token that never cancels — the explicit default (Null Object, not a static). */
    public static function none(): self
    {
        return new self(null);
    }

    public function isCancelled(): bool
    {
        return $this->predicate !== null && ($this->predicate)() === true;
    }
}
