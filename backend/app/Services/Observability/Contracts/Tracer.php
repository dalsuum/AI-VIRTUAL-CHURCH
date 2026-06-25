<?php

namespace App\Services\Observability\Contracts;

/**
 * Low-boilerplate tracing seam. Services wrap work in `span()` and optionally `annotate()` the
 * current span with observational attributes — no manual start/stop, no trace plumbing through
 * every method. A single shared (request-scoped) tracer keeps the span STACK, so spans opened by
 * deeper layers (retrieval, fusion, rerank) nest correctly under the chat-request root without
 * those layers knowing about the parent.
 *
 * The default binding is NullTracer (zero overhead); SpanTracer is bound only when observability
 * is enabled, so instrumentation is free in production until you turn it on.
 */
interface Tracer
{
    /**
     * Open a root trace for a correlation id, run $fn, then flush the completed tree to the store.
     * @template T  @param callable():T $fn  @return T
     * @param array<string,mixed> $attributes
     */
    public function trace(string $correlationId, string $name, callable $fn, array $attributes = []): mixed;

    /**
     * Run $fn inside a child span of the currently-open span (a no-op wrapper when no trace is
     * active). @template T  @param callable():T $fn  @return T
     * @param array<string,mixed> $attributes
     */
    public function span(string $name, callable $fn, array $attributes = []): mixed;

    /** Merge attributes into the currently-open span. @param array<string,mixed> $attributes */
    public function annotate(array $attributes): void;
}
