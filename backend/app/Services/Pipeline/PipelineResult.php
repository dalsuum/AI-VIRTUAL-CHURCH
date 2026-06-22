<?php

namespace App\Services\Pipeline;

/**
 * The outcome of a pipeline's hard path: the JSON payload + HTTP status to return, and
 * whether the soft-path (post-commit) hooks should run. A short-circuit result — e.g. a
 * crisis intercept or a deduplicated re-submission that did no new work — sets
 * runHooks=false so best-effort enrichment is skipped.
 */
final class PipelineResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly array $payload,
        public readonly int $status = 200,
        public readonly bool $runHooks = true,
    ) {}
}
