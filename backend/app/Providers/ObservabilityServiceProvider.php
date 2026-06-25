<?php

namespace App\Providers;

use App\Services\Observability\Contracts\Tracer;
use App\Services\Observability\Contracts\TraceStore;
use App\Services\Observability\NullTracer;
use App\Services\Observability\SpanTracer;
use App\Services\Observability\Store\RedisTraceStore;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the observability layer. The Tracer is a request-scoped SINGLETON so its span stack is
 * shared across every layer in one request (chat → retrieval → fusion → rerank), giving correct
 * nesting without trace plumbing. Bound to NullTracer unless tracing is enabled AND this request
 * is sampled — so instrumentation stays free in production.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TraceStore::class, fn ($app) => new RedisTraceStore(
            $app->make(Redis::class),
            (int) config('observability.tracing.ttl', 86400),
            (string) config('observability.tracing.redis_connection', 'default'),
        ));

        $this->app->singleton(Tracer::class, function ($app) {
            if (! config('observability.tracing.enabled') || ! $this->sampled()) {
                return new NullTracer();
            }

            return new SpanTracer($app->make(TraceStore::class));
        });
    }

    /** Per-request sampling decision (100% in dev, a few % in prod via OBSERVABILITY_SAMPLE_RATE). */
    private function sampled(): bool
    {
        $rate = (float) config('observability.tracing.sample_rate', 1.0);

        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) <= $rate;
    }
}
