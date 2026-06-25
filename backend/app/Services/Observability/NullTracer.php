<?php

namespace App\Services\Observability;

use App\Services\Observability\Contracts\Tracer;

/**
 * Zero-overhead default: runs the work, records nothing. Bound when observability is disabled so
 * every traced call site is free in production until tracing is turned on. The canonical Null
 * Object — instrumentation can live permanently in the code with no cost when off.
 */
final class NullTracer implements Tracer
{
    public function trace(string $correlationId, string $name, callable $fn, array $attributes = []): mixed
    {
        return $fn();
    }

    public function span(string $name, callable $fn, array $attributes = []): mixed
    {
        return $fn();
    }

    public function annotate(array $attributes): void {}
}
