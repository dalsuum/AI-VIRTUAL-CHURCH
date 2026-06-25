<?php

namespace App\Services\Observability;

use App\Services\Observability\Contracts\Tracer;
use App\Services\Observability\Contracts\TraceStore;

/**
 * Stack-based tracer for a single synchronous request. `trace()` opens the root and, on
 * completion, flushes the whole tree to the TraceStore keyed by correlation id. `span()` pushes a
 * child under whatever span is currently open, so nesting emerges from call order without any
 * layer passing trace context around. Exceptions still close spans and flush (the failure is part
 * of the trace), then re-throw.
 */
final class SpanTracer implements Tracer
{
    /** @var list<Span> open-span stack; top is the current parent */
    private array $stack = [];
    private ?string $correlationId = null;

    public function __construct(private readonly TraceStore $store) {}

    public function trace(string $correlationId, string $name, callable $fn, array $attributes = []): mixed
    {
        // Nested trace() (shouldn't happen per request) degrades to a plain span.
        if ($this->stack !== []) {
            return $this->span($name, $fn, $attributes);
        }

        $this->correlationId = $correlationId;
        $root = new Span($name, $attributes);
        $this->stack[] = $root;

        try {
            return $fn();
        } finally {
            $root->end();
            array_pop($this->stack);
            $this->store->put($correlationId, $root->toArray() + ['correlation_id' => $correlationId]);
            $this->correlationId = null;
        }
    }

    public function span(string $name, callable $fn, array $attributes = []): mixed
    {
        if ($this->stack === []) {
            return $fn(); // no active trace: run without recording (no orphan spans)
        }

        $span = new Span($name, $attributes);
        $this->stack[count($this->stack) - 1]->child($span);
        $this->stack[] = $span;

        try {
            return $fn();
        } finally {
            $span->end();
            array_pop($this->stack);
        }
    }

    public function annotate(array $attributes): void
    {
        if ($this->stack !== []) {
            $this->stack[count($this->stack) - 1]->annotate($attributes);
        }
    }
}
