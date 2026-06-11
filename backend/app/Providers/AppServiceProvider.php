<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One configured Stripe client, resolved wherever it's type-hinted
        // (e.g. OfferingService). Keeps the secret key out of the rest of the app.
        $this->app->singleton(StripeClient::class, function () {
            return new StripeClient(config('services.stripe.secret'));
        });
    }

    public function boot(): void
    {
        // Auth endpoints — keyed by IP so unauthenticated callers are also covered.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Intake triggers the full AI pipeline (LLM + TTS + music). Two guards:
        // a per-minute burst cap and a daily cap per authenticated user / IP.
        RateLimiter::for('intake', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return [
                Limit::perMinute(3)->by($key),
                Limit::perDay(20)->by($key),
            ];
        });

        // Testimony submissions — held for moderation, but still limit spam.
        RateLimiter::for('testimony', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
