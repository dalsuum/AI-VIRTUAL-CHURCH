<?php

namespace App\Providers;

use App\Services\Inference\InferenceGateway;
use App\Services\Inference\InferenceMetrics;
use App\Services\Inference\ModelRegistry;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Inference Provider Layer into the container. Everything is a singleton so
 * the in-process provider memoisation and a single metrics sink are shared per request.
 * Consumers (orchestrators, pipeline execute()) type-hint InferenceGateway only.
 */
class InferenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModelRegistry::class, fn ($app) => new ModelRegistry(
            $app->make(Http::class),
            $app->make(Cache::class),
        ));

        $this->app->singleton(InferenceMetrics::class);

        $this->app->singleton(InferenceGateway::class, fn ($app) => new InferenceGateway(
            $app->make(ModelRegistry::class),
            $app->make(InferenceMetrics::class),
        ));
    }
}
