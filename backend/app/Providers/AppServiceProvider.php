<?php

namespace App\Providers;

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
        //
    }
}
